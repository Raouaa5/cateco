module.exports = {
  content: [
    './templates/**/*.twig',
    './themes/**/*.twig',
    './assets/**/*.js',
    './vendor/sylius/sylius/src/Sylius/Bundle/UiBundle/Resources/views/**/*.twig',
    './vendor/sylius/sylius/src/Sylius/Bundle/CoreBundle/Resources/views/**/*.twig',
    './vendor/sylius/sylius/src/Sylius/Bundle/ShopBundle/Resources/views/**/*.twig'
  ],
  theme: {
    extend: {
      colors: {
        'cateco-orange': '#F97316',
        'cateco-dark': '#1F2937',
        'cateco-gold': '#F59E0B'
      }
    }
  },
  plugins: [],
}
