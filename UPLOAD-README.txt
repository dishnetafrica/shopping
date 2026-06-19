CATALOGUE FLUSH — refresh the shop cache WITHOUT logging anyone out

WHY
  `php artisan cache:clear` wipes the whole cache store, which on this setup includes your
  panel SESSIONS — that's why it logged you out. This adds a targeted flush that clears only
  the storefront catalogue cache and leaves sessions alone.

WHAT'S IN HERE
  NEW      app/Console/Commands/CatalogueFlushCommand.php
  REPLACE  app/Console/Commands/CatalogueRecategorizeCommand.php   (now auto-flushes; no longer
                                                                    tells you to run cache:clear)

USE IT
  After importing products / changing prices / stock / categories, run:
      php artisan catalogue:flush              # all shops
      php artisan catalogue:flush --tenant=1   # just Family Shoppers
  You will NOT be logged out.

  From now on, use  catalogue:flush  instead of  cache:clear  whenever you just need the
  storefront to pick up catalogue changes. (catalogue:recategorize now flushes by itself.)

UPLOAD ON GITHUB
  Add file -> Upload files -> drag the "app" folder -> Commit -> EasyPanel Deploy.
