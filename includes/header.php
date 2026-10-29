<?php
// includes/header.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$currentUser = getCurrentUser();
$role = $currentUser['role'] ?? 'Employee';
$companySettings = getCompanySettings();
$companyName = $companySettings['company_name'] ?? 'Zafar Brothers Iron Merchants';
$companyUrdu = (!empty($companySettings['company_urdu']) && strpos($companySettings['company_urdu'], '?') === false) ? $companySettings['company_urdu'] : 'ظفر برادرز آئرن مرچنٹس';
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> · <?= htmlspecialchars($companyName) ?></title>
  
  <!-- Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- FontAwesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <!-- Custom Design CSS -->
  <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body>

<div class="app-wrapper">
  <!-- Modular Sidebar Navigation -->
  <?php require_once __DIR__ . '/sidebar.php'; ?>

  <!-- Main Content Layout -->
  <div class="app-main">
    <!-- Sticky Header Banner (Preserving Zafar Brothers Brand Identity) -->
    <header class="header-banner">
      <button class="btn btn-sm btn-outline-light d-lg-none me-2" onclick="document.querySelector('.app-sidebar').classList.toggle('show')">
        <i class="fa-solid fa-bars"></i>
      </button>

      <img src="assets/images/logo.png" alt="Zafar Brothers Logo">

      <div class="hn">
        <div class="ur"><?= htmlspecialchars($companyUrdu) ?></div>
        <b><?= htmlspecialchars($companyName) ?> · Gujranwala Steel Group</b>
      </div>

      <div class="clock" id="liveClock">
        <b><?= date('h:i A') ?></b>
        <span><?= date('D, d M Y') ?></span>
      </div>
    </header>

    <!-- Page Body Container -->
    <main class="page-container">
      <?php 
        $flash = getFlash();
        if ($flash): 
      ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 8px;">
          <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> me-2"></i>
          <strong><?= htmlspecialchars($flash['message']) ?></strong>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif (!empty($_SESSION['flash_msg'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'success') ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 8px;">
          <strong><?= htmlspecialchars($_SESSION['flash_msg']) ?></strong>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
      <?php endif; ?>
