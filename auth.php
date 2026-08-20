<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentDoctor(): ?array {
    return (isset($_SESSION['doctor']) && is_array($_SESSION['doctor'])) ? $_SESSION['doctor'] : null;
}

function isLoggedIn(): bool {
    return currentDoctor() !== null;
}

function requireDoctor(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}
?>