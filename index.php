<?php
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fundraising Menu</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/theme.css">
  <style>a.card-link{text-decoration:none}</style>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="icon" href="favicon.ico">
  <meta name="robots" content="noindex">
  <meta name="color-scheme" content="light">
  <style>
    .menu-card .btn{min-width:110px}
  </style>
  </head>
<body>
<nav class="navbar navbar-expand-lg bg-brand">
  <div class="container">
    <a class="navbar-brand text-white fw-semibold" href="./">Church Fundraising</a>
  </div>
  </nav>

<div class="container py-5">
  <div class="text-center mb-4">
    <h2 class="fw-bold">Choose a section</h2>
    <div class="text-muted">Quick access menu</div>
  </div>
  <div class="row g-3 justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
      <a class="card-link" href="admin/login.php">
        <div class="card h-100 shadow-sm menu-card">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <h5 class="card-title">Admin</h5>
              <p class="card-text">Dashboard, approvals, settings</p>
            </div>
            <span class="btn btn-primary mt-2">Open Admin</span>
          </div>
        </div>
      </a>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <a class="card-link" href="registrar/login.php">
        <div class="card h-100 shadow-sm menu-card">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <h5 class="card-title">Registrar</h5>
              <p class="card-text">Registration form and tools</p>
            </div>
            <span class="btn btn-primary mt-2">Open Registrar</span>
          </div>
        </div>
      </a>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <a class="card-link" href="public/projector/">
        <div class="card h-100 shadow-sm menu-card">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <h5 class="card-title">Live View (Projector)</h5>
              <p class="card-text">Real-time totals and ticker</p>
            </div>
            <span class="btn btn-primary mt-2">Open Live View</span>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>
<footer class="py-4 bg-brand mt-5">
  <div class="container small">&copy; <?php echo date('Y'); ?> Church Fundraising</div>
  </footer>
</body>
</html>
