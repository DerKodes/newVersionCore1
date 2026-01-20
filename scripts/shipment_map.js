let map = null;
let routingControl = null; // Store the route controller
let mapModal = null; // Store the Bootstrap modal instance

function openShipmentMap(origin, destination, code) {
  // ---------------------------------------------------------
  // 1. BOOTSTRAP MODAL INITIALIZATION (Updated)
  // ---------------------------------------------------------
  const modalEl = document.getElementById("mapModal");

  // Create a new instance (or reuse existing if you prefer, but new is safer here)
  if (!mapModal) {
    mapModal = new bootstrap.Modal(modalEl);
  }

  mapModal.show();
  document.getElementById("mapTitle").innerText = "Tracking: " + code;

  // ---------------------------------------------------------
  // 2. INITIALIZE MAP (Inside setTimeout)
  // ---------------------------------------------------------
  // We wait 500ms for the Bootstrap animation to finish sliding in.
  // Leaflet needs the div to be visible/sized before it draws.
  setTimeout(() => {
    // Reset map if it exists (prevents "Map container is already initialized" error)
    if (map) {
      map.remove();
      map = null;
    }

    // Default View (Philippines)
    map = L.map("shipmentMap").setView([14.6, 121.0], 10);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "© OpenStreetMap",
    }).addTo(map);

    // 3. Find Locations & Draw Route
    Promise.all([geocodeWithFallback(origin), geocodeWithFallback(destination)])
      .then((coords) => {
        let start = coords[0];
        let end = coords[1];

        if (!start || !end) {
          alert("Map Error: Could not locate one or both addresses.");
          return;
        }

        // 🟢 REAL ROAD ROUTING (OSRM)
        routingControl = L.Routing.control({
          waypoints: [L.latLng(start[0], start[1]), L.latLng(end[0], end[1])],
          routeWhileDragging: false,
          addWaypoints: false, // User can't change path
          draggableWaypoints: false, // Lock the points
          fitSelectedRoutes: true, // Auto-zoom to fit the route
          show: false, // Hide the text instructions (Turn left, etc.)
          lineOptions: {
            styles: [{ color: "blue", opacity: 0.7, weight: 5 }],
          },
          createMarker: function (i, wp, nWps) {
            // Custom markers for Start (A) and End (B)
            let label =
              i === 0 ? "Origin: " + origin : "Destination: " + destination;
            return L.marker(wp.latLng).bindPopup(label).openPopup();
          },
        }).addTo(map);
      })
      .catch((err) => {
        console.error(err);
        alert("Map Error: " + err.message);
      });
  }, 500); // Wait 500ms for modal transition
}

// NOTE: With Bootstrap, we don't strictly need a custom closeMap() function
// because the "X" button or clicking outside handles it.
// However, if you have a custom "Close" button calling this, it's fine to keep.
function closeMap() {
  if (mapModal) {
    mapModal.hide();
  }
  // We don't remove the map here immediately; we do it next time it opens.
}

// ---------------------------------------------------------
// GEOCODING HELPER FUNCTIONS
// ---------------------------------------------------------

async function geocodeWithFallback(address) {
  try {
    return await geocode(address);
  } catch (e) {
    console.warn("Exact address failed, trying broad search...");
    try {
      let parts = address.split(",");
      if (parts.length > 2) {
        // Grab the last 3 parts (e.g., City, Province, Country)
        let broadAddress = parts.slice(-3).join(",");
        return await geocode(broadAddress);
      }
      return null;
    } catch (e2) {
      return null;
    }
  }
}

function geocode(address) {
  if (!address || address === "0") return Promise.reject("Invalid Address");

  // Use our PHP Proxy to avoid CORS errors
  let url = "../api/geocode.php?q=" + encodeURIComponent(address);

  return fetch(url)
    .then((res) => {
      if (!res.ok) throw new Error("Network error");
      return res.json();
    })
    .then((data) => {
      if (!data || data.length === 0 || data.error) {
        throw new Error("Address not found");
      }
      return [parseFloat(data[0].lat), parseFloat(data[0].lon)];
    });
}
