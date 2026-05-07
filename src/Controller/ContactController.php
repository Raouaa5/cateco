<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContactRequest;
use App\Form\Type\ContactRequestType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact')]
    public function index(
        Request $request, 
        EntityManagerInterface $entityManager, 
        SluggerInterface $slugger,
        \App\Service\OdooJsonRpcClient $odooClient,
        \Psr\Log\LoggerInterface $odooLogger
    ): Response
    {
        $contactRequest = new ContactRequest();
        $form = $this->createForm(ContactRequestType::class, $contactRequest);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $attachmentFile */
            $attachmentFile = $form->get('attachment')->getData();

            if ($attachmentFile) {
                $originalFilename = pathinfo($attachmentFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$attachmentFile->guessExtension();

                try {
                    $attachmentFile->move(
                        $this->getParameter('kernel.project_dir').'/public/uploads/contact',
                        $newFilename
                    );
                } catch (FileException $e) {
                    $this->addFlash('error', "Une erreur s'est produite lors du téléchargement de votre fichier.");
                }

                $contactRequest->setAttachment($newFilename);
            }

            $entityManager->persist($contactRequest);
            $entityManager->flush();

            // Odoo CRM Integration
            try {
                $partnerId = null;
                $email = (string) $contactRequest->getEmail();
                $phone = null;
                $name = '';
    
                $user = $this->getUser();
                if ($user && method_exists($user, 'getCustomer') && $user->getCustomer()) {
                    // Case A: Logged In User
                    $customer = $user->getCustomer();
                    $firstName = (string) $customer->getFirstName();
                    $lastName = (string) $customer->getLastName();
                    $name = trim($firstName . ' ' . $lastName);
                    $phone = $customer->getPhoneNumber();
    
                    // Explicitly link/upsert the partner
                    $partnerId = $odooClient->createOrUpdatePartner(
                        $name ?: 'Sylius Customer', // Fallback in case name is empty
                        $email,
                        $phone
                    );
                } else {
                    // Case B: Visitor
                    // Extract generic name from email since name is not in the form
                    $nameParts = explode('@', $email);
                    $name = $nameParts[0];
                }
    
                $leadId = $odooClient->createLead(
                    (string) $contactRequest->getSubject(),
                    $name,
                    $email,
                    $phone,
                    $contactRequest->getMessage(),
                    $partnerId
                );
    
                $odooLogger->info('Odoo lead created successfully from contact form', [
                    'name'       => $name,
                    'email'      => $email,
                    'partner_id' => $partnerId,
                    'lead_id'    => $leadId
                ]);
            } catch (\Exception $e) {
                $odooLogger->error('Failed to create Odoo lead from contact form', [
                    'email' => $contactRequest->getEmail(),
                    'error' => $e->getMessage()
                ]);
            }

            return $this->redirectToRoute('app_contact', ['success' => 1]);
        }

        return $this->render('shop/Contact/index.html.twig', [
            'form'   => $form->createView(),
        ]);
    }
}
