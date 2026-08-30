This folder is where a LOCAL copy of Chart.js goes, so the Analytics
dashboard's charts do not depend on internet/CDN access at all.

No file is bundled here yet — this sandbox has no network access, so a
real Chart.js file could not be downloaded and placed here for you. To
enable the local (fastest, offline-proof) path, do this once:

1. On any machine with internet access, download the UMD build of
   Chart.js 4.4.4 from either:
     https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js
     https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js
   (Right-click -> Save As, or `curl` it to a file.)

2. Save it as exactly:
     assets/js/vendor/chart.umd.min.js
   i.e. in XAMPP: C:\xampp\htdocs\three-sisters\assets\js\vendor\chart.umd.min.js

3. That's it — no code change needed. analytics/index.php already checks
   for this file's existence on the server and will automatically use it
   instead of any CDN the moment it's present.

If this file is absent, the page falls back to loading Chart.js from a
CDN (cdnjs, then jsdelivr if the first fails), and falls back further to
a plain data table if neither CDN is reachable — see analytics/index.php.
