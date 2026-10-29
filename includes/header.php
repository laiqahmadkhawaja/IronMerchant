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
    <!-- Executive Header Banner -->
    <header class="header-banner">
      <!-- Left: Logo & Brand Information -->
      <div class="header-brand-left">
        <button class="btn btn-sm btn-outline-light d-lg-none" onclick="document.querySelector('.app-sidebar').classList.toggle('show')" title="Toggle Menu">
          <i class="fa-solid fa-bars"></i>
        </button>

        <div class="header-logo-wrapper">
          <img src="assets/images/logo.png" alt="Zafar Brothers Logo">
        </div>

        <div class="header-brand-text">
          <div class="header-company-title"><?= htmlspecialchars($companyName) ?></div>
          <div class="header-company-subtitle">
            <span>Gujranwala Steel Group</span>
            <span class="badge">EST. 1988</span>
          </div>
        </div>
      </div>

      <!-- Center: Urdu Calligraphy & Motto -->
      <div class="header-center-crest d-none d-md-flex">
        <div class="header-urdu-title"><?= htmlspecialchars($companyUrdu) ?></div>
        <div class="header-urdu-motto">آپ کے اعتماد کا شکریہ — معیاری سریا، ایماندار وزن</div>
      </div>

      <!-- Right: User Identity & Digital Clock -->
      <div class="header-right-tools">
        <div class="header-user-pill d-none d-xl-flex">
          <div class="header-user-avatar">
            <i class="fa-solid fa-user-tie"></i>
          </div>
          <div class="header-user-info">
            <span class="user-name"><?= htmlspecialchars($currentUser['name'] ?? 'User') ?></span>
            <span class="user-role"><?= htmlspecialchars($role) ?></span>
          </div>
        </div>

        <div class="header-clock-card" id="liveClockCard">
          <div class="clock-icon">
            <i class="fa-regular fa-clock"></i>
          </div>
          <div class="clock-details" id="liveClock">
            <div class="clock-time" id="liveClockTime"><?= date('h:i:s A') ?></div>
            <div class="clock-date" id="liveClockDate"><?= date('D, d M Y') ?></div>
          </div>
        </div>
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
