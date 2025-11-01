<?php
/**
 * Login page for College Grading System
 * Handles user authentication
 */

require_once 'config.php';

// Redirect if already logged in
if (function_exists('isLoggedIn') && isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'instructor':
            header('Location: instructor/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
    }
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $email = function_exists('sanitize') ? sanitize($email) : htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        try {
            // Fetch user from database
            if (!isset($pdo)) {
                throw new Exception('Database connection not available.');
            }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Verify password
            if ($user && password_verify($password, $user['password'])) {
                // Set session variables
                // regenerate session id after login to prevent fixation
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['student_id'] = $user['student_id'];
                $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['program_id'] = $user['program_id'];
                $_SESSION['year_level'] = $user['year_level'];
                
                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        header('Location: admin/dashboard.php');
                        break;
                    case 'instructor':
                        header('Location: instructor/dashboard.php');
                        break;
                    case 'student':
                        header('Location: student/dashboard.php');
                        break;
                }
                exit;
            } else {
                // Distinguish user-not-found vs wrong-password to aid debugging (consider removing in production)
                if (!$user) {
                    $error = 'No active account found for that email.';
                } else {
                    $error = 'Invalid password.';
                }
            }
        } catch (Exception $e) {
            // Log the real error server-side for debugging
            error_log('Login error: ' . $e->getMessage());
            $error = 'Login error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="public/assets/icon/logo.svg">
    <style>
        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        /* Login Container */
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Header Section */
        .login-header {
            background: linear-gradient(135deg, #6a0dad 0%, #9b59b6 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .login-header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        /* Form Section */
        .login-form {
            padding: 40px 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #6a0dad;
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.1);
        }
        
        /* Error Message */
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        /* Login Button */
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #6a0dad 0%, #9b59b6 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(106, 13, 173, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        /* Footer */
        .login-footer {
            text-align: center;
            padding: 20px 30px;
            background: #f8f9fa;
            color: #666;
            font-size: 13px;
        }
        
        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                border-radius: 10px;
            }
            
            .login-header {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 24px;
            }
            
            .login-form {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Login Container -->
    <div class="login-container">
        <!-- Header -->
        <div class="login-header">
            <h1><?php echo SITE_NAME; ?></h1>
            <p>Please login to continue</p>
        </div>
        
        <!-- Login Form -->
        <div class="login-form">
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        required 
                        placeholder="Enter your email"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required 
                        placeholder="Enter your password"
                    >
                </div>
                
                <button type="submit" class="btn-login">Login</button>
            </form>
        </div>
        
        <!-- Footer -->
        <div class="login-footer">
            © 2024 <?php echo htmlspecialchars(SITE_NAME); ?>. All rights reserved.
            <div style="margin-top:8px;">
                <a href="forgot.php" style="color:#fff; text-decoration:underline;">Forgot your password?</a>
            </div>
        </div>
    </div>
</body>
</html>
