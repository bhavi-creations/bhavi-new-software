<?php include 'header.php'; ?>

<section class="login">
  <div class="container-fluid p-0">
    <div class="row g-0 login-layout">

      <!-- Left section -->
      <div class="col-lg-5 login-brand-panel">
        <a href="#" class="login-brand">
          <span>bhavi</span>
          <small>Team workspace</small>
        </a>

        <div class="login-brand-content">
          <h1>
            One team.
            <span>Every task in view.</span>
          </h1>

          <p class="login-description">
            Work, client briefs and leave requests<br class="d-none d-xl-block">
            in one connected workspace.
          </p>

          <div class="login-features">
            <div class="login-feature">
              <strong>Daily work</strong>
              <span>All departments</span>
            </div>

            <div class="login-feature">
              <strong>Client requirements</strong>
              <span>Clear briefs</span>
            </div>

            <div class="login-feature">
              <strong>Leave &amp; holidays</strong>
              <span>Better planning</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Right section -->
      <div class="col-lg-7 login-form-panel">
        <div class="login-card">
          <h2>Welcome back</h2>
          <p class="login-subtitle">
            Sign in with your assigned username and password.
          </p>

          <form action="authenticate.php" method="post">
            <!-- Add your server-generated CSRF token here. -->

            <div class="login-field">
              <label for="loginUsername" class="form-label">
                Username
              </label>

              <input
                type="text"
                class="form-control"
                id="loginUsername"
                name="username"
                placeholder="ananya.design"
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                required
              >
            </div>

            <div class="login-field login-password-field">
              <label for="loginPassword" class="form-label">
                Password
              </label>

              <div class="login-password-wrapper">
                <input
                  type="password"
                  class="form-control"
                  id="loginPassword"
                  name="password"
                  placeholder="************"
                  autocomplete="current-password"
                  required
                >

                <button
                  type="button"
                  class="login-password-toggle"
                  id="loginPasswordToggle"
                  aria-controls="loginPassword"
                  aria-label="Show password"
                  aria-pressed="false"
                >
                  Show
                </button>
              </div>
            </div>

            <!-- Sample error: render only after a failed login. -->
            <div class="login-error" role="alert">
              Incorrect username or password. Please try again.
            </div>

            <button type="submit" class="btn login-submit">
              Sign in
            </button>
          </form>

          <p class="login-help">
            Need login access? Contact your administrator.
          </p>

          <p class="login-access-note">
            Your account opens Manager, Admin or Employee access.
          </p>
        </div>
      </div>

    </div>
  </div>
</section>

<script>
    (() => {
  const section = document.querySelector(".login");
  if (!section) return;

  const password = section.querySelector("#loginPassword");
  const toggle = section.querySelector("#loginPasswordToggle");

  if (!password || !toggle) return;

  toggle.addEventListener("click", () => {
    const showPassword = password.type === "password";

    password.type = showPassword ? "text" : "password";
    toggle.textContent = showPassword ? "Hide" : "Show";
    toggle.setAttribute("aria-pressed", String(showPassword));
    toggle.setAttribute(
      "aria-label",
      showPassword ? "Hide password" : "Show password"
    );
  });
})();
</script>

<?php include 'footer.php'; ?>