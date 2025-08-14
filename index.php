<?php
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fundraising</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/theme.css">
  <style>a.card-link{text-decoration:none}</style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-brand">
  <div class="container">
    <a class="navbar-brand text-white fw-semibold" href="./">Church Fundraising</a>
  </div>
</nav>

<div class="container py-5">
  <div class="text-center mb-4">
    <h2 class="fw-bold">Welcome</h2>
    <div class="text-muted">Choose where you want to go</div>
  </div>
  <div class="row g-3">
    <div class="col-12 col-md-6 col-lg-3">
      <a class="card-link" href="public/visitor/">
        <div class="card h-100 shadow-sm">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <h5 class="card-title">Visitor</h5>
              <p class="card-text">Self-registration and pledges (coming soon)</p>
            </div>
            <span class="btn btn-primary mt-2">Open</span>
          </div>
        </div>
      </a>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <a class="card-link" href="admin/login.php">
        <div class="card h-100 shadow-sm">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <h5 class="card-title">Admin</h5>
              <p class="card-text">Dashboard, approvals, and settings</p>
            </div>
            <span class="btn btn-primary mt-2">Open</span>
          </div>
        </div>
      </a>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <a class="card-link" href="volunteer/">
        <div class="card h-100 shadow-sm">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <h5 class="card-title">Volunteer</h5>
              <p class="card-text">Registrar entry form (coming soon)</p>
            </div>
            <span class="btn btn-primary mt-2">Open</span>
          </div>
        </div>
      </a>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <a class="card-link" href="public/projector/open.php">
        <div class="card h-100 shadow-sm">
          <div class="card-body d-flex flex-column justify-content-between">
            <div>
              <h5 class="card-title">Projector View</h5>
              <p class="card-text">Live totals and ticker (requires token)</p>
            </div>
            <span class="btn btn-primary mt-2">Open</span>
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
