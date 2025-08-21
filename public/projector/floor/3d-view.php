<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3D Floor Map - Church Fundraising</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
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
            max-width: 250px;
            transition: opacity 0.3s ease;
        }

        #controls.hidden {
            opacity: 0;
            pointer-events: none;
        }

        #controls h3 {
            margin: 0 0 15px 0;
            font-size: clamp(14px, 2vw, 16px);
            color: #ffd700;
        }

        .control-group {
            margin-bottom: 15px;
        }

        .control-group label {
            display: block;
            margin-bottom: 5px;
            font-size: clamp(10px, 1.5vw, 12px);
            color: #cccccc;
        }

        .control-group input {
            width: 100%;
            margin-bottom: 5px;
        }

        .control-group input[type="color"] {
            width: clamp(40px, 6vw, 50px);
            height: clamp(25px, 4vw, 30px);
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .control-group input[type="range"] {
            width: 100%;
        }

        .control-group span {
            font-size: clamp(9px, 1.2vw, 11px);
            color: #888;
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
            transition: opacity 0.3s ease;
        }

        #file-input.hidden {
            opacity: 0;
            pointer-events: none;
        }

        #file-input h3 {
            margin: 0 0 10px 0;
            color: #ffd700;
            font-size: clamp(12px, 1.8vw, 14px);
        }

        #file-input input[type="file"] {
            margin-bottom: 10px;
            font-size: clamp(10px, 1.4vw, 12px);
        }

        #file-input button {
            background: #ffd700;
            color: #000;
            border: none;
            padding: clamp(6px, 1.2vw, 8px) clamp(12px, 2vw, 16px);
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: clamp(10px, 1.4vw, 12px);
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
            font-size: clamp(10px, 1.4vw, 12px);
            transition: opacity 0.3s ease;
        }

        #info.hidden {
            opacity: 0;
            pointer-events: none;
        }

        #fullscreen-btn {
            position: absolute;
            top: 20px;
            right: 200px;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            padding: clamp(8px, 1.5vw, 10px) clamp(12px, 2vw, 15px);
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(12px, 1.8vw, 14px);
            transition: opacity 0.3s ease;
        }

        #fullscreen-btn.hidden {
            opacity: 0;
            pointer-events: none;
        }

        #fullscreen-btn:hover {
            background: rgba(0, 0, 0, 0.9);
        }

        .color-preview {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #fff;
            border-radius: 3px;
            margin-left: 10px;
            vertical-align: middle;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            #controls {
                max-width: 200px;
                padding: 12px;
            }
            
            #file-input {
                padding: 12px;
                max-width: 180px;
            }
            
            #fullscreen-btn {
                right: 150px;
            }
        }

        @media (max-width: 480px) {
            #controls {
                max-width: 180px;
                padding: 10px;
            }
            
            #file-input {
                padding: 10px;
                max-width: 160px;
            }
            
            #fullscreen-btn {
                right: 120px;
            }
        }
    </style>
</head>
<body>
    <div id="container">
        <div id="loading">Loading 3D Scene...</div>
        
        <div id="controls">
            <h3>Floor Controls</h3>
            
            <div class="control-group">
                <label>Floor Color:</label>
                <input type="color" id="floor-color" value="#8B4513">
                <span>Main floor color</span>
            </div>

            <div class="control-group">
                <label>Transparency:</label>
                <input type="range" id="opacity" min="0.1" max="1" value="0.8" step="0.1">
                <span id="opacity-value">0.8</span>
            </div>

            <div class="control-group">
                <label>Camera Height:</label>
                <input type="range" id="camera-height" min="10" max="100" value="50" step="1">
                <span id="height-value">50</span>
            </div>

            <div class="control-group">
                <label>Auto Rotate:</label>
                <input type="checkbox" id="auto-rotate">
            </div>

            <div class="control-group">
                <label>Rotation Speed:</label>
                <input type="range" id="rotation-speed" min="0" max="5" value="0.3" step="0.1">
                <span id="rotation-value">0.3</span>
            </div>

            <div class="control-group">
                <label>Light Intensity:</label>
                <input type="range" id="light-intensity" min="0.1" max="2" value="1" step="0.1">
                <span id="light-value">1.0</span>
            </div>

            <div class="control-group">
                <label>Model Brightness:</label>
                <input type="range" id="model-brightness" min="0.1" max="3" value="1.5" step="0.1">
                <span id="brightness-value">1.5</span>
            </div>

            <div class="control-group">
                <label>Background Color:</label>
                <input type="color" id="background-color" value="#1a1a1a">
                <span>Scene background</span>
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
        let autoRotate = false;
        let rotationSpeed = 0.3;
        let directionalLight;

        // Initialize the 3D scene
        function init() {
            // Create scene
            scene = new THREE.Scene();
            scene.background = new THREE.Color(0x1a1a1a);

            // Create camera positioned above for top-down view
            camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
            camera.position.set(0, 50, 0); // Position camera above
            camera.lookAt(0, 0, 0); // Look down at the floor

            // Create renderer
            renderer = new THREE.WebGLRenderer({ antialias: true });
            renderer.setSize(window.innerWidth, window.innerHeight);
            renderer.shadowMap.enabled = true;
            renderer.shadowMap.type = THREE.PCFSoftShadowMap;
            document.getElementById('container').appendChild(renderer.domElement);

            // Add brighter lights for better visibility
            const ambientLight = new THREE.AmbientLight(0xffffff, 0.8); // Much brighter ambient light
            scene.add(ambientLight);

            directionalLight = new THREE.DirectionalLight(0xffffff, 1.5); // Brighter directional light
            directionalLight.position.set(0, 100, 0); // Light from above
            directionalLight.castShadow = true;
            scene.add(directionalLight);

            // Add additional lights for better coverage
            const leftLight = new THREE.DirectionalLight(0xffffff, 0.8);
            leftLight.position.set(-50, 50, 0);
            scene.add(leftLight);

            const rightLight = new THREE.DirectionalLight(0xffffff, 0.8);
            rightLight.position.set(50, 50, 0);
            scene.add(rightLight);

            // Add controls
            controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true;
            controls.dampingFactor = 0.05;
            controls.autoRotate = autoRotate;
            controls.autoRotateSpeed = rotationSpeed;
            
            // Remove rotation restrictions for full 365-degree control
            // controls.minPolarAngle = 0; // Commented out for full rotation
            // controls.maxPolarAngle = Math.PI / 2.5; // Commented out for full rotation

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
                    const scale = 30 / maxDim;
                    object.scale.setScalar(scale);
                    
                    // Apply current color and opacity settings
                    applyMaterialSettings(object);
                    
                    scene.add(object);
                    currentModel = object;
                    
                    // Adjust camera to fit model from above
                    const distance = Math.max(size.x, size.y, size.z) * 1.5;
                    camera.position.set(0, distance, 0);
                    controls.target.set(0, 0, 0);
                    controls.update();
                    
                    console.log('3D Floor Model loaded successfully!');
                    
                } catch (error) {
                    console.error('Error loading OBJ:', error);
                    alert('Error loading the 3D model. Please check the file format.');
                }
            };

            reader.readAsText(file);
        }

        // Apply material settings to the model
        function applyMaterialSettings(object) {
            const floorColor = document.getElementById('floor-color').value;
            const opacity = parseFloat(document.getElementById('opacity').value);
            
            object.traverse(function(child) {
                if (child.isMesh) {
                    child.material = new THREE.MeshPhongMaterial({
                        color: floorColor,
                        transparent: true,
                        opacity: opacity,
                        side: THREE.DoubleSide,
                        shininess: 100, // Increased shininess for better reflection
                        specular: 0x444444, // Add specular highlights
                        emissive: 0x222222, // Add slight glow for better visibility
                        flatShading: false, // Smooth shading for better appearance
                        emissiveIntensity: parseFloat(document.getElementById('model-brightness').value) // Apply model brightness
                    });
                }
            });
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

            // Color control
            document.getElementById('floor-color').addEventListener('input', function(e) {
                if (currentModel) {
                    applyMaterialSettings(currentModel);
                }
            });

            // Opacity control
            document.getElementById('opacity').addEventListener('input', function(e) {
                const opacity = parseFloat(e.target.value);
                document.getElementById('opacity-value').textContent = opacity;
                if (currentModel) {
                    applyMaterialSettings(currentModel);
                }
            });

            // Camera height control
            document.getElementById('camera-height').addEventListener('input', function(e) {
                const height = parseFloat(e.target.value);
                document.getElementById('height-value').textContent = height;
                camera.position.y = height;
                controls.update();
            });

            // Rotation speed control
            document.getElementById('rotation-speed').addEventListener('input', function(e) {
                rotationSpeed = parseFloat(e.target.value);
                document.getElementById('rotation-value').textContent = rotationSpeed;
                controls.autoRotateSpeed = rotationSpeed;
            });

            // Auto rotate control
            document.getElementById('auto-rotate').addEventListener('change', function(e) {
                autoRotate = e.target.checked;
                controls.autoRotate = autoRotate;
            });

            // Light intensity control
            document.getElementById('light-intensity').addEventListener('input', function(e) {
                const intensity = parseFloat(e.target.value);
                document.getElementById('light-value').textContent = intensity.toFixed(1);
                if (directionalLight) {
                    directionalLight.intensity = intensity;
                }
            });

            // Model brightness control
            document.getElementById('model-brightness').addEventListener('input', function(e) {
                const brightness = parseFloat(e.target.value);
                document.getElementById('brightness-value').textContent = brightness.toFixed(1);
                if (currentModel) {
                    applyMaterialSettings(currentModel);
                }
            });

            // Background color control
            document.getElementById('background-color').addEventListener('input', function(e) {
                const bgColor = e.target.value;
                scene.background = new THREE.Color(bgColor);
            });

            // Fullscreen
            document.getElementById('fullscreen-btn').addEventListener('click', function() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen();
                } else {
                    document.exitFullscreen();
                }
            });

            // Handle fullscreen changes
            document.addEventListener('fullscreenchange', function() {
                const controls = document.getElementById('controls');
                const fileInput = document.getElementById('file-input');
                const info = document.getElementById('info');
                const fullscreenBtn = document.getElementById('fullscreen-btn');
                
                if (document.fullscreenElement) {
                    // Hide controls in fullscreen
                    controls.classList.add('hidden');
                    fileInput.classList.add('hidden');
                    info.classList.add('hidden');
                    fullscreenBtn.classList.add('hidden');
                } else {
                    // Show controls when exiting fullscreen
                    controls.classList.remove('hidden');
                    fileInput.classList.remove('hidden');
                    info.classList.remove('hidden');
                    fullscreenBtn.classList.remove('hidden');
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
                
                // Toggle controls visibility with 'C' key
                if (e.key === 'c' || e.key === 'C') {
                    const controls = document.getElementById('controls');
                    const fileInput = document.getElementById('file-input');
                    const info = document.getElementById('info');
                    const fullscreenBtn = document.getElementById('fullscreen-btn');
                    
                    if (controls.classList.contains('hidden')) {
                        controls.classList.remove('hidden');
                        fileInput.classList.remove('hidden');
                        info.classList.remove('hidden');
                        fullscreenBtn.classList.remove('hidden');
                    } else {
                        controls.classList.add('hidden');
                        fileInput.classList.add('hidden');
                        info.classList.add('hidden');
                        fullscreenBtn.classList.add('hidden');
                    }
                }
            });
        });
    </script>
</body>
</html>
