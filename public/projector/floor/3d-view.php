<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3D Floor Map - Church Fundraising</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #1a1a1a;
            font-family: system-ui, -apple-system, sans-serif;
            overflow: hidden;
        }

        #container {
            width: 100vw;
            height: 100vh;
            position: relative;
        }

        #loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #ffffff;
            font-size: 24px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.8);
            padding: 20px;
            border-radius: 10px;
        }

        #controls {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.7);
            padding: 15px;
            border-radius: 8px;
            color: white;
        }

        #controls h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
        }

        .control-group {
            margin-bottom: 10px;
        }

        .control-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
        }

        .control-group input {
            width: 100px;
            margin-right: 10px;
        }

        #file-input {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.7);
            padding: 15px;
            border-radius: 8px;
            color: white;
        }

        #file-input input[type="file"] {
            margin-bottom: 10px;
        }

        #file-input button {
            background: #ffd700;
            color: #000;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }

        #file-input button:hover {
            background: #ffed4e;
        }

        #info {
            position: absolute;
            bottom: 20px;
            left: 20px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.7);
            padding: 15px;
            border-radius: 8px;
            color: white;
            font-size: 12px;
        }

        #fullscreen-btn {
            position: absolute;
            top: 20px;
            right: 200px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        #fullscreen-btn:hover {
            background: rgba(0, 0, 0, 0.9);
        }
    </style>
</head>
<body>
    <div id="container">
        <div id="loading">Loading 3D Scene...</div>
        
        <div id="controls">
            <h3>Camera Controls</h3>
            <div class="control-group">
                <label>Camera Distance:</label>
                <input type="range" id="distance" min="5" max="50" value="20" step="1">
                <span id="distance-value">20</span>
            </div>
            <div class="control-group">
                <label>Rotation Speed:</label>
                <input type="range" id="rotation-speed" min="0" max="2" value="0.5" step="0.1">
                <span id="rotation-value">0.5</span>
            </div>
            <div class="control-group">
                <label>Auto Rotate:</label>
                <input type="checkbox" id="auto-rotate" checked>
            </div>
        </div>

        <div id="file-input">
            <h3>Load 3D Model</h3>
            <input type="file" id="obj-file" accept=".obj" />
            <button id="load-btn">Load Model</button>
        </div>

        <button id="fullscreen-btn">Fullscreen</button>

        <div id="info">
            <strong>3D Floor Map Controls:</strong><br>
            • Mouse: Rotate camera<br>
            • Scroll: Zoom in/out<br>
            • Right click: Pan<br>
            • Double click: Reset view
        </div>
    </div>

    <!-- Three.js Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/OBJLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>

    <script>
        let scene, camera, renderer, controls;
        let currentModel = null;
        let autoRotate = true;
        let rotationSpeed = 0.5;

        // Initialize the 3D scene
        function init() {
            // Create scene
            scene = new THREE.Scene();
            scene.background = new THREE.Color(0x1a1a1a);

            // Create camera
            camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
            camera.position.set(0, 20, 20);

            // Create renderer
            renderer = new THREE.WebGLRenderer({ antialias: true });
            renderer.setSize(window.innerWidth, window.innerHeight);
            renderer.shadowMap.enabled = true;
            renderer.shadowMap.type = THREE.PCFSoftShadowMap;
            document.getElementById('container').appendChild(renderer.domElement);

            // Add lights
            const ambientLight = new THREE.AmbientLight(0x404040, 0.6);
            scene.add(ambientLight);

            const directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);
            directionalLight.position.set(10, 10, 5);
            directionalLight.castShadow = true;
            scene.add(directionalLight);

            // Add controls
            controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true;
            controls.dampingFactor = 0.05;
            controls.autoRotate = autoRotate;
            controls.autoRotateSpeed = rotationSpeed;

            // Add grid helper
            const gridHelper = new THREE.GridHelper(50, 50, 0x444444, 0x222222);
            scene.add(gridHelper);

            // Handle window resize
            window.addEventListener('resize', onWindowResize, false);

            // Start animation loop
            animate();

            // Hide loading
            document.getElementById('loading').style.display = 'none';
        }

        // Animation loop
        function animate() {
            requestAnimationFrame(animate);
            controls.update();
            renderer.render(scene, camera);
        }

        // Handle window resize
        function onWindowResize() {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        }

        // Load OBJ file
        function loadOBJ(file) {
            const loader = new THREE.OBJLoader();
            const reader = new FileReader();

            reader.onload = function(event) {
                const objData = event.target.result;
                
                // Clear previous model
                if (currentModel) {
                    scene.remove(currentModel);
                }

                // Load the OBJ data
                try {
                    const object = loader.parse(objData);
                    
                    // Center and scale the model
                    const box = new THREE.Box3().setFromObject(object);
                    const center = box.getCenter(new THREE.Vector3());
                    const size = box.getSize(new THREE.Vector3());
                    
                    // Center the model
                    object.position.sub(center);
                    
                    // Scale to fit in view
                    const maxDim = Math.max(size.x, size.y, size.z);
                    const scale = 20 / maxDim;
                    object.scale.setScalar(scale);
                    
                    // Add material to make it visible
                    object.traverse(function(child) {
                        if (child.isMesh) {
                            child.material = new THREE.MeshPhongMaterial({
                                color: 0x8B4513,
                                transparent: true,
                                opacity: 0.8,
                                side: THREE.DoubleSide
                            });
                        }
                    });
                    
                    scene.add(object);
                    currentModel = object;
                    
                    // Adjust camera to fit model
                    const distance = Math.max(size.x, size.y, size.z) * 2;
                    camera.position.set(distance, distance, distance);
                    controls.target.set(0, 0, 0);
                    controls.update();
                    
                    console.log('3D Model loaded successfully!');
                    
                } catch (error) {
                    console.error('Error loading OBJ:', error);
                    alert('Error loading the 3D model. Please check the file format.');
                }
            };

            reader.readAsText(file);
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize scene
            init();

            // File input handling
            document.getElementById('load-btn').addEventListener('click', function() {
                const fileInput = document.getElementById('obj-file');
                if (fileInput.files.length > 0) {
                    loadOBJ(fileInput.files[0]);
                } else {
                    alert('Please select an OBJ file first.');
                }
            });

            // Controls
            document.getElementById('distance').addEventListener('input', function(e) {
                const distance = parseFloat(e.target.value);
                document.getElementById('distance-value').textContent = distance;
                camera.position.set(distance, distance, distance);
                controls.update();
            });

            document.getElementById('rotation-speed').addEventListener('input', function(e) {
                rotationSpeed = parseFloat(e.target.value);
                document.getElementById('rotation-value').textContent = rotationSpeed;
                controls.autoRotateSpeed = rotationSpeed;
            });

            document.getElementById('auto-rotate').addEventListener('change', function(e) {
                autoRotate = e.target.checked;
                controls.autoRotate = autoRotate;
            });

            // Fullscreen
            document.getElementById('fullscreen-btn').addEventListener('click', function() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen();
                } else {
                    document.exitFullscreen();
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'f' || e.key === 'F') {
                    if (!document.fullscreenElement) {
                        document.documentElement.requestFullscreen();
                    } else {
                        document.exitFullscreen();
                    }
                }
            });
        });
    </script>
</body>
</html>
