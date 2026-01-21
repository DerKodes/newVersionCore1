document.addEventListener("DOMContentLoaded", function () {
  // =========================================================
  // 1. INJECT LOADER HTML & CSS (Updated Animation)
  // =========================================================
  const loaderHTML = `
      <div id="global-loader">
          <div class="loader-content">
              <img src="../assets/slate.png" alt="Loading..." class="loader-logo">
              <div class="loader-text">SYNCING...</div>
          </div>
      </div>
  `;
  document.body.insertAdjacentHTML("afterbegin", loaderHTML);

  const loaderStyle = document.createElement("style");
  loaderStyle.innerHTML = `
      /* LOADER CONTAINER */
      #global-loader {
          position: fixed;
          top: 0; left: 0; width: 100%; height: 100%;
          background-color: #2b2b2ba2; /* Always White */
          z-index: 99999;
          display: flex; justify-content: center; align-items: center;
          transition: opacity 0.5s ease, visibility 0.5s;
          opacity: 1; visibility: visible;
      }

      /* CONTENT WRAPPER */
      .loader-content {
          text-align: center;
          display: flex;
          flex-direction: column;
          align-items: center;
      }

      /* LOGO ANIMATION (PULSE + BLUR) */
      .loader-logo {
          width: 80px;
          height: auto;
          margin-bottom: 15px;
          animation: logo-pulse 2s infinite ease-in-out;
      }

      /* TEXT STYLE */
      .loader-text {
          font-family: 'Segoe UI', sans-serif;
          font-weight: 700;
          font-size: 14px;
          letter-spacing: 2px;
          color: #0d6efd; /* Primary Blue */
          animation: text-blink 1.5s infinite ease-in-out;
      }

      /* HIDE STATE */
      #global-loader.hidden {
          opacity: 0;
          visibility: hidden;
      }

      /* ANIMATIONS */
      @keyframes logo-pulse {
          0% { 
              transform: scale(1); 
              filter: blur(0px); 
              opacity: 1;
          }
          50% { 
              transform: scale(1.1); 
              filter: blur(2px); /* Slight Blur Effect */
              opacity: 0.8;
          }
          100% { 
              transform: scale(1); 
              filter: blur(0px); 
              opacity: 1;
          }
      }

      @keyframes text-blink {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.5; }
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
  // 4. SWEETALERT: SUCCESS TOASTS
  // =========================================
  const urlParams = new URLSearchParams(window.location.search);

  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
  });

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
  // 5. LOADER LOGIC
  // =========================================================
  const loader = document.getElementById("global-loader");

  // Hide loader after a short delay
  setTimeout(() => {
    loader.classList.add("hidden");
  }, 600);

  // Show loader when clicking valid links
  document.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", function (e) {
      const target = this.getAttribute("href");

      if (
        !target ||
        target.startsWith("#") ||
        target.startsWith("javascript") ||
        this.getAttribute("target") === "_blank" ||
        e.ctrlKey ||
        e.metaKey
      ) {
        return;
      }

      loader.classList.remove("hidden");
    });
  });
});

// =========================================================
// 6. BACK BUTTON FIX
// =========================================================
window.addEventListener("pageshow", function (event) {
  if (event.persisted) {
    const loader = document.getElementById("global-loader");
    if (loader) loader.classList.add("hidden");
  }
});

// =========================================
// 7. GLOBAL SWEETALERT CONFIRMATIONS
// =========================================

function confirmLogout() {
  // Check if Dark Mode is active to style the alert
  const isDarkMode = document.body.classList.contains("dark-mode");

  Swal.fire({
    title: "Ready to leave?",
    text: "Your session will be closed securely.",

    // BRANDING: Use your logo instead of a generic icon
    imageUrl: "../assets/slate.png",
    imageWidth: 70,
    imageHeight: "auto",
    imageAlt: "System Logo",

    // THEME ADAPTATION
    background: isDarkMode ? "#1e1e1e" : "#ffffff",
    color: isDarkMode ? "#e0e0e0" : "#333333",

    // BUTTONS & UX
    showCancelButton: true,
    confirmButtonColor: "#dc3545", // Red (Bootstrap Danger)
    cancelButtonColor: "#6c757d", // Grey (Bootstrap Secondary)
    confirmButtonText: "Sign Out",
    cancelButtonText: "Stay Here",
    reverseButtons: true, // Puts 'Sign Out' on the right
    focusCancel: true, // Focuses 'Stay Here' by default (prevents accidents)

    // ANIMATION
    showClass: {
      popup: "swal2-show",
      backdrop: "swal2-backdrop-show",
      icon: "swal2-icon-show",
    },
    hideClass: {
      popup: "swal2-hide",
      backdrop: "swal2-backdrop-hide",
      icon: "swal2-icon-hide",
    },
  }).then((result) => {
    if (result.isConfirmed) {
      // Trigger Loader
      const loader = document.getElementById("global-loader");
      if (loader) {
        // Change text to indicate logging out
        const loaderText = loader.querySelector(".loader-text");
        if (loaderText) loaderText.textContent = "SIGNING OUT...";
        loader.classList.remove("hidden");
      }

      // Redirect
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
      if (loader) loader.classList.remove("hidden");
      form.submit();
    }
  });
}
// =========================================
