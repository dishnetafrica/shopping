BLINKIT-STYLE CATEGORIES — consolidate 43 messy categories into ~18-20 clean ones

WHAT IT DOES
  Re-maps every product into a small, clean, Blinkit-style category set, and SPLITS the
  giant "Food" bucket into proper sub-categories (Snacks, Spices, Beverages, Rice & Flour,
  Sauces, Sweets, Breakfast, etc.) using the product names. The new exercise books fold
  into "Stationery & Office". It also rebuilds the category list in a nice order (food first)
  and moves your existing category photos onto the matching new categories.

  FULLY REVERSIBLE: the original category of every product is backed up the first time it runs.

FILE
  NEW  app/Console/Commands/CatalogueRecategorizeCommand.php

UPLOAD ON GITHUB
  Add file -> Upload files -> drag the "app" folder -> Commit -> EasyPanel Deploy.

RUN (in the shopping console)
  php artisan catalogue:recategorize --dry      # 1) PREVIEW: see the new categories + counts, no changes
  php artisan catalogue:recategorize            # 2) apply it
  php artisan cache:clear                        # 3) so the storefront/bot rebuild

  (Defaults to Family Shoppers. For another shop: --tenant=<id>.)

UNDO (if you don't like it)
  php artisan catalogue:recategorize --restore
  php artisan cache:clear

NOTE
  Always run the --dry preview first and eyeball the category list. If you want a grouping
  changed (merge/rename/split), tell me and I'll adjust the rules before you apply.
