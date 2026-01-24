document.addEventListener("DOMContentLoaded", function () {
  // =========================================================
  // 1. INJECT PREMIUM LOADER (Glassmorphism & Progress Bar)
  // =========================================================
  const loaderHTML = `
      <div id="global-loader">
          <div class="loader-content">
              <div class="loader-logo-wrapper">
                  <img src="../assets/slate.png" alt="Loading..." class="loader-logo">
              </div>
              <div class="loader-progress-wrap">
                  <div class="loader-progress-bar"></div>
              </div>
              <div class="loader-text">IDENTIFYING MODULE...</div>
          </div>
      </div>
  `;
  document.body.insertAdjacentHTML("afterbegin", loaderHTML);

  const loaderStyle = document.createElement("style");
  loaderStyle.innerHTML = `
      #global-loader {
          position: fixed;
          top: 0; left: 0; width: 100%; height: 100%;
          background: rgba(255, 255, 255, 0.8);
          backdrop-filter: blur(15px) saturate(180%);
          -webkit-backdrop-filter: blur(15px) saturate(180%);
          z-index: 999999;
          display: flex; justify-content: center; align-items: center;
          transition: opacity 0.4s ease, visibility 0.4s;
          opacity: 0; visibility: hidden;
      }

      body.dark-mode #global-loader {
          background: rgba(18, 18, 18, 0.8);
      }

      #global-loader.active {
          opacity: 1; visibility: visible;
      }

      .loader-content {
          text-align: center;
          display: flex; flex-direction: column; align-items: center;
          gap: 25px;
      }

      .loader-logo-wrapper {
          position: relative;
          padding: 15px;
      }

      .loader-logo {
          width: 90px; height: auto;
          filter: drop-shadow(0 10px 15px rgba(0,0,0,0.1));
          animation: premium-pulse 2s infinite ease-in-out;
      }

      .loader-progress-wrap {
          width: 160px; height: 3px;
          background: rgba(0, 0, 0, 0.05);
          border-radius: 10px; overflow: hidden;
          position: relative;
      }

      body.dark-mode .loader-progress-wrap { background: rgba(255, 255, 255, 0.1); }

      .loader-progress-bar {
          width: 40%; height: 100%;
          background: linear-gradient(90deg, #4e73df, #224abe);
          border-radius: 10px;
          position: absolute; left: -40%;
          animation: loader-slide 1.5s infinite ease-in-out;
      }

      .loader-text {
          font-family: 'Inter', 'Segoe UI', sans-serif;
          font-weight: 800;
          font-size: 11px;
          letter-spacing: 4px;
          color: #4e73df;
          text-transform: uppercase;
          opacity: 0.9;
      }

      body.dark-mode .loader-text { color: #7591e8; }

      @keyframes premium-pulse {
          0% { transform: scale(1); opacity: 1; }
          50% { transform: scale(1.08); opacity: 0.8; }
          100% { transform: scale(1); opacity: 1; }
      }

      @keyframes loader-slide {
          0% { left: -40%; width: 20%; }
          50% { left: 30%; width: 40%; }
          100% { left: 110%; width: 20%; }
      }
  `;
  document.head.appendChild(loaderStyle);

  // =========================================
  // 2. SIDEBAR TOGGLE
  // =========================================
  const hamburger = document.getElementById("hamburger");
  const body = document.body;

  if (hamburger) {
    hamburger.addEventListener("click", () => {
      body.classList.toggle("sidebar-closed");
    });
  }

  // =========================================
  // 3. DARK MODE (PERSISTENT)
  // =========================================
  const themeToggle = document.getElementById("themeToggle");

  if (localStorage.getItem("theme") === "dark") {
    body.classList.add("dark-mode");
    if (themeToggle) themeToggle.checked = true;
  }

  if (themeToggle) {
    themeToggle.addEventListener("change", () => {
      if (themeToggle.checked) {
        body.classList.add("dark-mode");
        localStorage.setItem("theme", "dark");
      } else {
        body.classList.remove("dark-mode");
        localStorage.setItem("theme", "light");
      }
    });
  }

  // =========================================
  // 4. ALERTS & TOASTS MANAGER
  // =========================================
  const urlParams = new URLSearchParams(window.location.search);
  const isDarkMode = document.body.classList.contains("dark-mode");

  // A. STANDARD TOAST (For updates, creation)
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
    background: isDarkMode ? "#1e1e1e" : "#fff",
    color: isDarkMode ? "#fff" : "#333",
  });

  // B. ✨ PROFESSIONAL LOGIN SUCCESS ALERT (Center Screen)
  if (urlParams.has("login_success")) {
    // Try to grab the user's name from the header (if it exists in your PHP DOM)
    // Assumes structure: <span class="..."><?= $_SESSION['full_name'] ?></span>
    const userNameElement = document.querySelector(".dropdown-toggle span");
    const userName = userNameElement
      ? userNameElement.innerText.trim()
      : "User";

    Swal.fire({
      title: `<span style="font-weight: 300;">Welcome back,</span> <br><b>${userName}</b>`,
      html: '<span class="text-muted" style="font-size: 0.9rem;">SLATE FREIGHT SYSTEM IS READY</span>',
      imageUrl: "../assets/slate.png",
      imageWidth: 80,
      imageHeight: "auto",
      imageAlt: "Slate Logo",

      timer: 2500, // Auto close after 2.5 seconds
      timerProgressBar: true,
      showConfirmButton: false,

      // Theme Styling
      background: isDarkMode ? "#1e1e1e" : "#ffffff",
      color: isDarkMode ? "#e0e0e0" : "#333333",
      backdrop: `rgba(0,0,0,0.4)`, // Dim background slightly
      padding: "2rem",
      customClass: {
        popup: "animated fadeInDown", // Smooth entry
      },
    });
    cleanUrl();
  }

  // C. STANDARD ALERTS
  if (urlParams.has("created")) {
    Toast.fire({ icon: "success", title: "Record created successfully!" });
    cleanUrl();
  }
  if (urlParams.has("updated")) {
    Toast.fire({ icon: "success", title: "Status updated successfully!" });
    cleanUrl();
  }
  if (urlParams.has("success")) {
    Toast.fire({ icon: "success", title: "Operation successful!" });
    cleanUrl();
  }
  if (urlParams.has("decon")) {
    Toast.fire({ icon: "warning", title: "Items deconsolidated." });
    cleanUrl();
  }

  function cleanUrl() {
    window.history.replaceState(null, null, window.location.pathname);
  }

  // =========================================================
  // 5. LOADER LOGIC (Switching Modules Only)
  // =========================================================
  const loader = document.getElementById("global-loader");
  const isModuleSwitch = sessionStorage.getItem("module_switching") === "true";

  if (isModuleSwitch) {
    loader.classList.add("active");
    sessionStorage.removeItem("module_switching");
    setTimeout(() => {
      loader.classList.remove("active");
    }, 850);
  }

  // Show loader on navigation (ONLY IF SWITCHING TO A DIFFERENT PHP MODULE)
  document.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", function (e) {
      const href = this.getAttribute("href");

      if (
        !href ||
        href.startsWith("#") ||
        href.startsWith("javascript") ||
        this.getAttribute("target") === "_blank" ||
        e.ctrlKey || e.metaKey
      ) {
        return;
      }

      const currentPath = window.location.pathname.split("/").pop() || "index.php";
      let targetPath = "";
      try {
        const url = new URL(href, window.location.origin + window.location.pathname);
        targetPath = url.pathname.split("/").pop();
      } catch (err) {
        targetPath = href.split("?")[0].split("/").pop();
      }

      // If switching to a different file (module)
      if (currentPath !== targetPath && targetPath.endsWith(".php")) {
        sessionStorage.setItem("module_switching", "true");

        // Set dynamic text based on link text
        let linkLabel = this.innerText.trim();
        if (linkLabel && linkLabel.length < 25) {
          loader.querySelector(".loader-text").innerText = `OPENING ${linkLabel.toUpperCase()}...`;
        } else {
          loader.querySelector(".loader-text").innerText = "LOADING MODULE...";
        }

        loader.classList.add("active");
      }
    });
  });
});

// =========================================================
// 6. BACK BUTTON FIX
// =========================================================
window.addEventListener("pageshow", function (event) {
  if (event.persisted) {
    const loader = document.getElementById("global-loader");
    if (loader) loader.classList.remove("active");
  }
});

// =========================================
// 7. GLOBAL CONFIRMATIONS
// =========================================

function confirmLogout() {
  const isDarkMode = document.body.classList.contains("dark-mode");

  Swal.fire({
    title: "Ready to leave?",
    text: "Session will be closed.",
    imageUrl: "../assets/slate.png",
    imageWidth: 60,

    background: isDarkMode ? "#1e1e1e" : "#ffffff",
    color: isDarkMode ? "#e0e0e0" : "#333333",

    showCancelButton: true,
    confirmButtonColor: "#dc3545",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "Sign Out",
    cancelButtonText: "Stay",
    reverseButtons: true,
    focusCancel: true,
  }).then((result) => {
    if (result.isConfirmed) {
      const loader = document.getElementById("global-loader");
      if (loader) {
        // Change text for logout
        loader.querySelector(".loader-text").innerText = "CLOSING SESSION...";
        loader.classList.add("active");
      }
      window.location.href = "../login/logout.php";
    }
  });
}

function confirmFormAction(event, title = "Are you sure?", icon = "warning") {
  event.preventDefault();
  const form = event.target;
  const isDarkMode = document.body.classList.contains("dark-mode");

  Swal.fire({
    title: title,
    icon: icon,
    background: isDarkMode ? "#1e1e1e" : "#ffffff",
    color: isDarkMode ? "#e0e0e0" : "#333333",
    showCancelButton: true,
    confirmButtonColor: "#3085d6",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, proceed",
  }).then((result) => {
    if (result.isConfirmed) {
      const loader = document.getElementById("global-loader");
      if (loader) {
        loader.querySelector(".loader-text").innerText = "PROCESSING...";
        loader.classList.add("active");
      }
      form.submit();
    }
  });
}
