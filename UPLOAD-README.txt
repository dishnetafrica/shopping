WIN WORLD — files to add to your repo (dishnetafrica/shopping)

NEW / CHANGED in this upload (paths are exact):
  app/Services/Winworld/CustomerMessages.php          (NEW)
  app/Http/Controllers/Panel/WinworldSalesController.php  (REPLACES existing)
  database/seeders/WinworldDemoSeeder.php             (NEW)
  qa/ww_customer_msgs.php                             (NEW, dev test - optional)

HOW TO UPLOAD ON GITHUB:
  1. Open your repo on github.com.
  2. Add file -> Upload files.
  3. Drag the "app", "database", and "qa" folders from this unzipped folder into the page
     (GitHub keeps the folder paths). 
  4. Commit changes.
  5. EasyPanel -> Deploy.

ONE MANUAL EDIT (not in this zip, to avoid overwriting your other panel changes):
  In resources/panel/seller.html, add this line right after the "Setup" nav line:
  <a class="nav" href="/panel/winworld"><span class="i">[factory]</span> Win World</a>
  (use the factory emoji)

AFTER DEPLOY - load the demo data (run once in the EasyPanel console):
  php artisan db:seed --class=WinworldDemoSeeder
