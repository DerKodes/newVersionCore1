let map = null;
let routingControl = null;

// Function to initialize map directly in a div (No Modal)
function initEmbeddedMap(origin, destination) {
  // 1. Reset if exists to prevent "Map container is already initialized" error
  if (map) {
    map.remove();
    map = null;
  }

  // 2. Initialize Leaflet
  // Ensure the div with id="shipmentMap" exists before running this
  var mapContainer = document.getElementById("shipmentMap");
  if (!mapContainer) {
    console.error("Map container #shipmentMap not found!");
    return;
  }

  map = L.map("shipmentMap").setView([14.6, 121.0], 10); // Default to Philippines

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "© OpenStreetMap",
  }).addTo(map);

  // 3. Geocode and Draw Route
  Promise.all([geocodeWithFallback(origin), geocodeWithFallback(destination)])
    .then((coords) => {
      let start = coords[0];
      let end = coords[1];

      if (!start || !end) {
        console.warn("Could not locate one or both addresses.");
        return;
      }

      // Draw Route using OSRM
      if (routingControl) {
        map.removeControl(routingControl);
      }

      routingControl = L.Routing.control({
        waypoints: [L.latLng(start[0], start[1]), L.latLng(end[0], end[1])],
        routeWhileDragging: false,
        addWaypoints: false,
        draggableWaypoints: false,
        fitSelectedRoutes: true,
        show: false, // Hide text instructions
        lineOptions: {
          styles: [{ color: "blue", opacity: 0.7, weight: 5 }],
        },
        createMarker: function (i, wp) {
          let label =
            i === 0 ? "Origin: " + origin : "Destination: " + destination;
          return L.marker(wp.latLng).bindPopup(label).openPopup();
        },
      }).addTo(map);
    })
    .catch((err) => {
      console.error("Map Error:", err);
    });
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
  // Make sure this path points correctly to your API folder relative to where the script is run
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
