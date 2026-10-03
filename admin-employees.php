<?php include 'header.php'; ?>


<section class="admin-employees">
  <!-- Sidebar -->
  <aside
    class="ae-sidebar offcanvas-lg offcanvas-start"
    id="adminEmployeesSidebar"
    tabindex="-1"
    aria-labelledby="adminEmployeesSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="adminEmployeesSidebarTitle">
        Admin menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#adminEmployeesSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="ae-sidebar-body">
      <a href="admin-dashboard.php" class="ae-brand">
        <span class="ae-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="ae-profile">
        <div class="ae-avatar">A</div>
        <h2>Admin</h2>
        <p>Account administrator</p>
      </div>

      <nav class="ae-navigation" aria-label="Admin navigation">
        <a href="admin-dashboard.php">
          <span></span>Dashboard
        </a>
        <a
          href="admin-employees.php"
          class="active"
          aria-current="page"
        >
          <span></span>Employees
        </a>
        <a href="admin-add-employee.php">
          <span></span>Add employee
        </a>
        <a href="admin-holidays.php">
          <span></span>Holidays
        </a>
      </nav>

      <div class="ae-workspace">
        <strong>Admin workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="ae-main">
    <header class="ae-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="ae-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#adminEmployeesSidebar"
          aria-controls="adminEmployeesSidebar"
          aria-label="Open menu"
        >
          <svg
            width="22"
            height="22"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path d="M4 6h16M4 12h16M4 18h16"></path>
          </svg>
        </button>

        <div class="ae-breadcrumb">
          Admin <span>/</span> Employees
        </div>
      </div>

      <div class="ae-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="ae-role">Admin</span>
      </div>
    </header>

    <div class="ae-content">
      <div class="ae-page-heading">
        <div>
          <h1>Employees</h1>
          <p>Manage individual accounts and department access.</p>
        </div>

        <a href="admin-add-employee.php" class="ae-add-button">
          Add employee
        </a>
      </div>

      <!-- Filters -->
      <div class="row ae-filter-row">
        <div class="col-12 col-md-7">
          <label for="aeEmployeeSearch" class="ae-label">
            Search employees
          </label>
          <input
            type="search"
            id="aeEmployeeSearch"
            name="search"
            class="form-control ae-input"
            placeholder="Search name or username"
          >
        </div>

        <div class="col-12 col-md-5">
          <label for="aeDepartment" class="ae-label">
            Department
          </label>
          <select
            id="aeDepartment"
            name="department"
            class="form-select ae-input ae-select"
          >
            <option value="">All departments</option>
            <option value="design">Design &amp; video</option>
            <option value="website">Website</option>
            <option value="seo">SEO</option>
            <option value="social">Social media</option>
            <option value="telecaller">Telecaller</option>
          </select>
        </div>
      </div>

      <!-- Employee table -->
      <div class="ae-table-card">
        <div
          class="table-responsive ae-table-scroll"
          tabindex="0"
          role="region"
          aria-label="Employee accounts table"
        >
          <table class="table ae-table">
            <thead>
              <tr>
                <th scope="col">Employee</th>
                <th scope="col">Username</th>
                <th scope="col">Department</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>

            <tbody>
              <tr>
                <td>Ananya Kumar</td>
                <td>ananya.design</td>
                <td>Design &amp; video</td>
                <td>Active</td>
                <td>
                  <div class="ae-actions">
                    <button type="button" aria-label="Edit Ananya Kumar">
                      Edit
                    </button>
                    <span aria-hidden="true">/</span>
                    <button
                      type="button"
                      aria-label="Reset password for Ananya Kumar"
                    >
                      Reset password
                    </button>
                  </div>
                </td>
              </tr>

              <tr>
                <td>Ravi Teja</td>
                <td>ravi.web</td>
                <td>Website</td>
                <td>Active</td>
                <td>
                  <div class="ae-actions">
                    <button type="button" aria-label="Edit Ravi Teja">
                      Edit
                    </button>
                    <span aria-hidden="true">/</span>
                    <button
                      type="button"
                      aria-label="Reset password for Ravi Teja"
                    >
                      Reset password
                    </button>
                  </div>
                </td>
              </tr>

              <tr>
                <td>Rahul Varma</td>
                <td>rahul.seo</td>
                <td>SEO</td>
                <td>Active</td>
                <td>
                  <div class="ae-actions">
                    <button type="button" aria-label="Edit Rahul Varma">
                      Edit
                    </button>
                    <span aria-hidden="true">/</span>
                    <button
                      type="button"
                      aria-label="Reset password for Rahul Varma"
                    >
                      Reset password
                    </button>
                  </div>
                </td>
              </tr>

              <tr>
                <td>Priya Rao</td>
                <td>priya.social</td>
                <td>Social media</td>
                <td>Active</td>
                <td>
                  <div class="ae-actions">
                    <button type="button" aria-label="Edit Priya Rao">
                      Edit
                    </button>
                    <span aria-hidden="true">/</span>
                    <button
                      type="button"
                      aria-label="Reset password for Priya Rao"
                    >
                      Reset password
                    </button>
                  </div>
                </td>
              </tr>

              <tr>
                <td>Neha Reddy</td>
                <td>neha.calls</td>
                <td>Telecaller</td>
                <td>Active</td>
                <td>
                  <div class="ae-actions">
                    <button type="button" aria-label="Edit Neha Reddy">
                      Edit
                    </button>
                    <span aria-hidden="true">/</span>
                    <button
                      type="button"
                      aria-label="Reset password for Neha Reddy"
                    >
                      Reset password
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="ae-account-summary">
        <span class="ae-active-count">10 active employees</span>
        <p>New accounts are included in Total employees automatically.</p>
      </div>
    </div>

    <footer class="ae-footer">
      <span>Bhavi Creations / Admin / UI/UX concept - sample data</span>
      <span>07 / Desktop</span>
    </footer>
  </div>
</section>




<?php include 'footer.php'; ?>