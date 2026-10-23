# Bundled third-party files (default theme 1.1)

Self-hosted so the theme loads nothing from another site. Content-hashed file names: a changed file always has a new name, so the
web server may send `Cache-Control: public, max-age=31536000, immutable` for this folder (see `includes/server-snippets/README.md`).

| File | Project | Version | License | Source and check |
|---|---|---|---|---|
| bootstrap-4.5.2.5b0fbe5b7a.min.css | Bootstrap | 4.5.2 | MIT | `bootstrap@4.5.2/dist/css/bootstrap.min.css`, SRI sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z |
| bootstrap-bundle-4.5.2.f67b782ec5.min.js | Bootstrap (with Popper) | 4.5.2 | MIT | `bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js`, SRI sha384-LtrjvnR4Twt/qOuYxE721u19sVFLVSA4hf/rRt6PrZTmiPltdZcI7q7PXQBYTKyf (same on jsDelivr and unpkg) |
| jquery-3.5.1.f7f6a5894f.min.js | jQuery | 3.5.1 | MIT | `jquery@3.5.1/dist/jquery.min.js`, SRI sha384-ZvpUoO/+PpLXR1lu4jmpXWu80pZlYUAfxl5NsBMWOEPSjUn/6Z/hRTt8+pR6L4N2 |

The 10 characters in each name are the start of the file's SHA-256. To update a library: add the new file with its own hash, change the
name in `functions.php`, delete the old file.
