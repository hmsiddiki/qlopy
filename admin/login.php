<?php
////check ins 1.2.1
if (!defined('QLOPY_INIT')) define('QLOPY_INIT', true);
require_once __DIR__ . '/../auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {

    $user = get_user_by_username($username);
if (!$user) {
    $error = 'Invalid username or password.';
} else {
    $required = ['manage_options','manage_posts','manage_users','manage_themes','manage_plugins','manage_admin_pages','manage_comments','moderate_comments','publish_posts'];
    $user_caps = $user['capabilities'] ?? [];
    $allowed = false;
    foreach ($required as $rc) {
        if (in_array($rc, $user_caps, true)) { $allowed = true; break; }
    }
    if (!$allowed) {
        $error = 'This account does not have permission to access the admin area.';
    } else {
        // proceed to attempt login
         
        if (login_user($username, $password)) {
            // Schedule an immediate update check via qp-cron (best-effort, non-blocking)
            try {
                // Ensure cron core/queue are available for scheduling when admin_head.php is not included on login page
                $qpCronFile = __DIR__ . '/../includes/qp-cron.php';
                if (file_exists($qpCronFile)) { require_once $qpCronFile; }
                $qpCronQueueFile = __DIR__ . '/../includes/qp-cron-queue.php';
                if (file_exists($qpCronQueueFile)) { require_once $qpCronQueueFile; }

                if (function_exists('qp_schedule_single_event')) {
                    // Schedule a login-triggered immediate checker under a distinct hook so
                    // it won't be blocked by an existing recurring 'qlopy_update_checker'
                    $shouldScheduleLogin = true;
                    if (function_exists('qp_get_scheduled_events')) {
                        $events = qp_get_scheduled_events();
                        foreach ($events as $ev) {
                            if (($ev['hook'] ?? '') === 'qlopy_update_checker_login' && in_array(strtolower($ev['status'] ?? ''), ['pending','running'])) { $shouldScheduleLogin = false; break; }
                        }
                    }
                    if ($shouldScheduleLogin) {
                        try {
                            $taskId = qp_schedule_single_event(time(), 'qlopy_update_checker_login', ['_origin' => 'admin'], 0);
                        } catch (Throwable $_e) {
                            $taskId = false;
                        }
                        if (function_exists('qp_cron_log')) {
                            qp_cron_log($taskId ?: null, 'qlopy_update_checker_login', $taskId ? 'scheduled' : 'schedule_failed', $taskId ? 'scheduled on login' : 'scheduling returned false');
                        } else {
                            error_log('qp_schedule_single_event (login) returned: ' . var_export($taskId, true));
                        }
                    }
                }
            } catch (Throwable $_) { /* best-effort: do not block login on scheduler errors */ }

            header('Location: index.php'); // Redirect to admin dashboard
            exit;
        } else {
            $error = 'Invalid username or password.';
        }



    }
}

        

    } else {
        $error = 'Please enter username and password.';
    }
}
$config = get_config();
$admin_url = $config['site_url'] .'/'. ($config['admin_dir'].'/' ?? '/admin/');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Admin Login</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($admin_url) ?>assets/bt_4.6.2/css/bootstrap.min.css?ver=4.6.2" media="all">
    <style>
        /* Simple centered login form styling */
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            height: 100vh;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 6px;
            box-shadow: 0 0 12px rgba(0,0,0,0.1);
            width: 300px;
        }

        .error {
            color: red;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
  
    </style>
</head>
<body>
    <div class="container-fluid h-100">
    <div class="row justify-content-center align-items-center h-100">
        <div class="col col-sm-6 col-md-6 col-lg-4 col-xl-3">
            <h2>Admin Login</h2>
             
    <form method="POST" action="">
        <div class="form-group">
        <label for="username">Username</label>
        <input id="username" class="form-control" type="text" name="username" required autofocus />
        </div> 
        <div class="form-group">
        <label for="password">Password</label>
        <input id="password" class="form-control" type="password" name="password" required />
         </div>
         <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
        <button  type="submit" class="btn btn-primary btn-block">Login</button>
    </form>
    
        </div>
    </div>
</div>
</body>
</html>
