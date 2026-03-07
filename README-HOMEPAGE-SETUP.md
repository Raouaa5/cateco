# Cateco Homepage Setup - Sylius 2.2.2

This document provides instructions for setting up and maintaining the custom homepage for the Cateco Sylius store.

## Files Created

### Twig Templates
All templates are located in `templates/bundles/SyliusShopBundle/Homepage/`:

1. **`index.html.twig`** - Main homepage template that extends `@SyliusShop/shared/layout/base.html.twig` and includes all partials
2. **`_hero.html.twig`** - Full-width hero banner with overlay text and CTA button
3. **`_categories.html.twig`** - Categories section with 3 category blocks linking to Sylius taxons
4. **`_products.html.twig`** - Latest products section showing 8 newest products

### Custom Twig Extension
- **`src/Twig/ProductsExtension.php`** - Provides `get_latest_products()` function

### Configuration
- **`config/packages/app_twig.yaml`** - Service registration for the Twig extension

## Design Features

### Hero Section
- Full-width responsive hero banner (`min-h-[60vh]` to `min-h-[80vh]`)
- Background image with dark overlay
- Large readable headings (`text-4xl` to `text-7xl`)
- CTA button with hover effects

### Categories Section
- 3 category cards with hover overlay effects
- Responsive aspect ratios (`aspect-[4/3]` to `aspect-[3/2]`)
- Scale animation on hover
- Links to Sylius taxons (nourriture, accessoires, soins)

### Products Section
- Responsive grid (`grid-cols-1` to `grid-cols-4`)
- Product cards with aspect ratio images
- Price display using cheapest variant from `channelPricings`
- "Voir le produit" button linking to product page

## Cache Clearing & Asset Building

### Using Docker

```
bash
# Clear Symfony cache
docker compose exec php bin/console cache:clear
docker compose exec php bin/console cache:clear --env=prod

# Install assets
docker compose exec php bin/console assets:install

# Build assets (if using Webpack)
docker compose exec node yarn build
# or
docker compose exec node npm run build
```

### Without Docker

```
bash
# Clear Symfony cache
php bin/console cache:clear
php bin/console cache:clear --env=prod

# Install assets
php bin/console assets:install

# Build assets
yarn build
# or
npm run build
```

### Development Mode

For development with hot reload:

```
bash
# Using Docker
docker compose exec node yarn dev

# Without Docker
yarn dev
# or
npm run dev
```

## Troubleshooting

### Route Not Found
If you get errors about missing routes:
```
bash
# Debug routes
docker compose exec php bin/console debug:router
```

### Assets Not Loading
```
bash
# Install assets with symlink
docker compose exec php bin/console assets:install --symlink

# Dump asset bundles
docker compose exec php bin/console assetic:dump
```

### Twig Template Errors
```
bash
# Clear cache completely
docker compose exec php bin/console cache:clear --all

# Debug Twig
docker compose exec php bin/console debug:twig
```

## Image Requirements

Place your images in `public/assets/shop/images/`:

- `hero-bg.png` - Hero background image
- `category-nourriture.avif` - Category: Nourriture
- `category-accessoires.avif` - Category: Accessoires
- `category-soins.avif` - Category: Soins

The templates will fall back to colored placeholders if images are missing.

## Sylius Version Compatibility

This setup is designed for **Sylius 2.2.2** with:
- Tailwind CSS v3+
- LiipImagineBundle for image processing
- Twig hooks for navbar customization

## Notes

- Product images use `sylius_large` filter - ensure this filter is configured in LiipImagine
- Categories link to taxon pages - ensure taxons with slugs (nourriture, accessoires, soins) exist
- The homepage uses the `sylius_shop.homepage.index` hook event
