# Feed Mixing — install steps (baby steps)

Do these in order. Stop and tell me if anything shows an error.

## Step 1 — Copy the NEW files into your project
Copy each file from this folder into the same place in `D:\My Projects\vero-cattle-mg\`
(the folder structure inside `feed-mixing` matches your project):

| From (inside feed-mixing) | To (your project) |
|---|---|
| database\migrations\2026_30_06_000024_create_feed_mixing_tables.php | database\migrations\ |
| database\migrations\2026_30_06_000025_add_feed_mixing_wip_account.php | database\migrations\ |
| app\Models\FeedMixingBatch.php | app\Models\ |
| app\Models\FeedMixingLine.php | app\Models\ |
| app\Services\FeedMixingService.php | app\Services\ |
| app\Http\Controllers\FeedMixingController.php | app\Http\Controllers\ |
| resources\js\Pages\FeedMixing\Index.vue, CreateEdit.vue, Show.vue | resources\js\Pages\FeedMixing\ (create the folder) |
| tests\Feature\Services\FeedMixingServiceTest.php | tests\Feature\Services\ |

## Step 2 — Replace the 7 CHANGED existing files
Inside `modified-existing-files` are 7 complete files. Each differs from yours by only a few lines.
Replace the matching file in your project (same path):

1. routes\web.php  (+ Feed Mixing routes)
2. app\Models\InventoryTransaction.php  (1 line)
3. app\Http\Controllers\StatementController.php  (2 spots)
4. resources\js\Pages\Accounting\Statements\Index.vue  (labels for the new types)
5. resources\js\Pages\Inventory\Transactions.vue  (labels for the new types)
6. resources\js\Layouts\AuthenticatedLayout.vue  (menu link "Feed Mixing")
7. database\seeders\ChartOfAccountsSeeder.php  (new accounts get 1360 automatically)

> If you edited any of these 7 files after uploading the zip, tell me first so I can merge instead of replace.

## Step 3 — Run in Command Prompt (in the project folder)
```
composer dump-autoload
php artisan migrate
npm run build
```
`php artisan migrate` should list the two new migrations as DONE.

## Step 4 — Check
1. Press Ctrl + F5 in the browser.
2. Menu → Feed → **Feed Mixing** appears.
3. Chart of Accounts → Current Assets now shows **1360 Feed Mixing WIP**.
4. In Inventory, make sure the finished mix exists once as an item with category **Feed**.

## Step 5 — Run the tests
```
php artisan test --filter=FeedMixingServiceTest
```

## Who can use it
It uses the existing **Feed** permissions (view / create / edit / delete feed). Feed Manager and Farm Manager already have them. No permission changes needed.
