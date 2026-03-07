<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        if (in_array($this->environment, ['dev', 'test'], true)) {
            return '/tmp/sylius/cache/' . $this->environment;
        }
        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if (in_array($this->environment, ['dev', 'test'], true)) {
            return '/tmp/sylius/log';
        }
        return parent::getLogDir();
    }
}
