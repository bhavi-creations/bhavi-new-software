<?php include 'header.php'; ?>

<section class="admin-clilient-add">
  <div class="aca-layout">

    <!-- Sidebar -->
   <aside
    class="ah-sidebar offcanvas-lg offcanvas-start"
    id="adminHolidaysSidebar"
    tabindex="-1"
    aria-labelledby="adminHolidaysSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="adminHolidaysSidebarTitle">
        Admin menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#adminHolidaysSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="ah-sidebar-body">
      <a href="admin-dashboard.php" class="ah-brand">
        <span class="ah-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="ah-profile">
        <div class="ah-avatar">A</div>
        <h2>Admin</h2>
        <p>Account administrator</p>
      </div>

      <nav class="ah-navigation" aria-label="Admin navigation">
        <a href="admin-dashboard.php">
          <span></span>Dashboard
        </a>
        <a href="admin-employees.php">
          <span></span>Employees
        </a>
        <a href="admin-add-employee.php">
          <span></span>Add employee
        </a>
        
        <a href="admin-holidays.php" class="active" aria-current="page">
          <span></span>Holidays
        </a>
      </nav>

      <div class="ah-workspace">
        <strong>Admin workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

    <!-- Main content -->
    <div class="aca-main">
      <header class="aca-topbar">
        <div class="d-flex align-items-center gap-3">
          <button
            type="button"
            class="aca-menu-toggle d-lg-none"
            data-bs-toggle="offcanvas"
            data-bs-target="#acaSidebar"
            aria-controls="acaSidebar"
            aria-label="Open admin menu"
          >
            <span></span>
            <span></span>
            <span></span>
          </button>

          <div class="aca-breadcrumb">
            <span>Admin</span>
            <span>/</span>
            <span>Add client</span>
          </div>
        </div>

        <div class="aca-topbar-right">
          <time datetime="2026-10-03" class="aca-date">
            03 October 2026
          </time>
          <span class="aca-role">Admin</span>
        </div>
      </header>

      <div class="aca-content">
        <div class="aca-heading">
          <h1>Add client</h1>
          <p>Add client details and upload the brand logo.</p>
        </div>

        <div class="aca-form-card">
          <form
            action="admin-save-client.php"
            method="post"
            enctype="multipart/form-data"
          >
            <!-- Insert your server-generated CSRF token here. -->

            <div class="row g-4 aca-fields">
              <div class="col-md-6">
                <label for="acaClientName" class="form-label">
                  Client name
                </label>

                <input
                  type="text"
                  class="form-control"
                  id="acaClientName"
                  name="client_name"
                  placeholder="Enter client name"
                  maxlength="150"
                  required
                >
              </div>

              <div class="col-md-6">
                <label for="acaWebsiteUrl" class="form-label">
                  Website URL
                </label>

                <input
                  type="url"
                  class="form-control"
                  id="acaWebsiteUrl"
                  name="website_url"
                  placeholder="https://www.example.com"
                  maxlength="2048"
                  required
                >
              </div>
            </div>

            <div class="aca-logo-field">
              <label for="acaLogoFile" class="form-label">
                Client logo
              </label>

              <div class="aca-upload">
                <div class="aca-upload-details">
                  <div class="aca-upload-icon">
                    <svg
                      viewBox="0 0 64 64"
                      fill="none"
                      aria-hidden="true"
                    >
                      <path
                        d="M19 47H15a11 11 0 0 1-1-22
                           18 18 0 0 1 35-1
                           12 12 0 0 1 0 23h-4"
                        stroke="currentColor"
                        stroke-width="4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                      <path
                        d="M32 54V31m-9 9 9-9 9 9"
                        stroke="currentColor"
                        stroke-width="4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                    </svg>
                  </div>

                  <div class="aca-upload-copy">
                    <h2>Upload client logo</h2>
                    <p id="acaLogoHelp">
                      PNG, JPG or SVG · Maximum 5 MB
                    </p>
                  </div>
                </div>

                <div class="aca-file-picker">
                  <span aria-hidden="true">Choose file</span>
                  <input
                    type="file"
                    id="acaLogoFile"
                    name="client_logo"
                    accept=".png,.jpg,.jpeg,.svg"
                    aria-describedby="acaLogoHelp acaLogoMessage"
                    required
                  >
                </div>
              </div>
            </div>

            <div class="aca-preview">
              <div class="aca-preview-box">
                <svg
                  id="acaPreviewIcon"
                  viewBox="0 0 48 48"
                  fill="none"
                  aria-hidden="true"
                >
                  <rect
                    x="6" y="6" width="36" height="36" rx="4"
                    stroke="currentColor" stroke-width="3"
                  />
                  <circle cx="16" cy="16" r="3" fill="currentColor"/>
                  <path
                    d="m7 35 11-11 7 7 7-10 9 12"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>

                <img id="acaLogoPreview" alt="Selected client logo" hidden>
              </div>

              <div class="aca-preview-copy">
                <h3>Logo preview</h3>
                <p id="acaLogoMessage" aria-live="polite">
                  Your uploaded logo will appear here.
                </p>
              </div>
            </div>

            <p class="aca-form-note">
              Client details and logo will be available in brand assets.
            </p>

            <div class="aca-actions">
              <button type="submit" class="btn aca-save">
                Add client
              </button>

              <a href="admin-clients.php" class="btn aca-cancel">
                Cancel
              </a>
            </div>
          </form>
        </div>

        <footer class="aca-footer">
          <span>Bhavi Creations / Admin / UI/UX concept</span>
          <span>08 / Desktop</span>
        </footer>
      </div>
    </div>

  </div>
</section>


<?php include 'footer.php'; ?>