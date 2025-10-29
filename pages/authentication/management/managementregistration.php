<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Admin/BAT</title>
    <link rel="stylesheet" href="style/managementregistration.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Administration Access</h1>
            <p class="sub">Choose how you want to manage InnoVision</p>
            <div class="options">
                <form>
                    <button class="opt admin" type="submit" formaction="admin/req.php">
                        <span class="icon">🛡️</span>
                        Register as Admin
                    </button>
                    <button class="opt bat" type="submit" formaction="bat/req.php">
                        <span class="icon">🏷️</span>
                        Register as BAT
                    </button>
                </form>
            </div>
            <div class="back"><a href="../registrationpage.php">Back</a></div>
        </div>
    </div>
</body>
</html>