<?php if (!defined('QLOPY_INIT')) { http_response_code(403); exit; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($page_title ?? 'Qlopy Admin') ?> - Qlopy Admin</title>


<?php if (function_exists('print_admin_styles')) { print_admin_styles(); } ?>
<?php if (function_exists('print_admin_header_scripts')) { print_admin_header_scripts(); } ?>
<?php if (function_exists('print_tinymce_config_bridge')) { print_tinymce_config_bridge(); } ?>
<script>
// Set global ajaxurl from admin_head helper for consistent usage
(function(){
  window.ajaxurl = <?= json_encode(function_exists('get_admin_ajax_url') ? get_admin_ajax_url() : 'ajax.php') ?>;
  window.qlopyAdminAjax = '<?= $admin_ajax_url ?>';
})();
</script>
<style>
  .actionheadbtn {
    position: relative;
    height: 32px;
    
}
  /* admheadmenu (the right one) */

.qp-branding {
    max-height: 30px;
    text-decoration: none !important;
    color: inherit !important;
}
.admheadmenu {
    top: calc(100% + 24px);
    width: 160px;
    background: #fff;
    box-shadow: 0 10px 20px rgba(0, 0, 0, .2);
    transform: translateY(-10px);
    transition: 300ms;
    right: 0;
    left: inherit;
}

.admheadmenu::before {
    content: '';
    position: absolute;
    top: -10px;
    right: 14px;
    border-bottom: 10px solid #dfdfdf;
    border-left: 10px solid #00000000;
    border-right: 10px solid transparent;
    z-index: -1;
}

.admheadmenu.show {
    opacity: 1;
    transform: translateY(0);
    visibility: visible;
}

/* admheadmenu links */

.admheadmenu ul {
    position: relative;
    display: flex;
    flex-direction: column;
    z-index: 10;
    background: #fff;
    padding: 0;
    margin: 0;
}

.admheadmenu ul li {
    list-style: none;
}

.admheadmenu ul li:hover {
    background: #eee;
}

.admheadmenu ul li a {
    text-decoration: none;
    color: #000;
    display: flex;
    align-items: center;
    padding: 10px 20px;
    gap: 6px;
}

.admheadmenu ul li a i {
    font-size: 1.2em;
}
.coloradminlogo{
  animation: changeColor infinite  linear  15s forwards;
  color:transparent;
background:linear-gradient(to bottom, #691bcd, rgb(153 141 141 / 36%), #691bcd) bottom left / 100% 600%;
background-clip:text;
font-size:2rem;
}

@keyframes changeColor {
   
 to  {  background-position: top left;
  }
}
.useradminmenu {
    display: flex;
    align-items: center;
    text-align: right;
    line-height: 1;
}
.useradminmenu img {
    height: 32px;
    width: 32px;
    border-radius: 10%;
    cursor: pointer;
    margin-left: 5px;
    -webkit-box-shadow: 2px 2px 5px 2px #a9a9a9;
    box-shadow: 2px 2px 5px 2px #a9a9a9;
}
.useradminmenu .user span {
    text-align: end;
    font-weight: 600;
    font-size: 0.9rem;
}
.useradminmenu .user p {
    font-size: 0.8rem;
    opacity: .6;
    margin: 0;
    padding: 0;
}
.visit_site{
    font-size: 0.7rem;
}
  </style>
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>
<div id="content" class="container-fluid content-wrapper">
