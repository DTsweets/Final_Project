<?php
session_start();

// ถ้า login อยู่แล้ว ให้ไป router
if (isset($_SESSION['user_id'])) {
    header('Location: router.php');
    exit;
}

// ตรวจสอบเวลา (19:00 - 05:59 เป็นกลางคืน)
date_default_timezone_set('Asia/Bangkok');
$currentHour = (int) date('G'); // 0-23
$isNight = ($currentHour >= 19 || $currentHour < 6);
$timeFolder = $isNight ? 'night' : 'day';
$textureSuffix = $isNight ? '_night' : '_day';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UP Net Zero - 3D Landing Page</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700&family=Kanit:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js" as="script">
    <link rel="preload" href="model/models/Room_Portfolio.glb" as="fetch" crossorigin>

    <!-- นำเข้า Google Model Viewer -->
    <script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.4.0/model-viewer.min.js"></script>

    <!-- นำเข้า GSAP สำหรับ Animation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body,
        html {
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: 'Kanit', sans-serif;
            background-color: #111;
            /* สีพื้นหลังระหว่างรอโหลดโมเดล */
        }

        /* Container สำหรับ 3D Model ให้เต็มจอ */
        #model-container {
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
        }

        model-viewer {
            width: 100%;
            height: 100%;
            --poster-color: transparent;
            /* เพิ่มเงาและแสงสะท้อนเบื้องต้นในตัว */
        }

        /* UI Overlay วางทับ Model */
        .ui-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            pointer-events: none;
            /* เพื่อให้ยังหมุน Model ทะลุ UI ได้ ยกเว้นตรงปุ่ม */
            padding: 2rem;
            background: linear-gradient(to bottom, rgba(0, 0, 0, 0.4) 0%, transparent 20%, transparent 80%, rgba(0, 0, 0, 0.6) 100%);
        }

        .header-content {
            text-align: center;
            color: white;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
            animation: fadeInDown 1s ease-out;
        }

        .header-content h1 {
            font-family: 'Inter', sans-serif;
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: 2px;
        }

        .header-content p {
            font-size: 1.2rem;
            font-weight: 400;
            opacity: 0.9;
        }

        .bottom-action {
            display: flex;
            justify-content: center;
            padding-bottom: 2rem;
            animation: fadeInUp 1s ease-out;
        }

        /* ปุ่มเข้าสู่ระบบ */
        .btn-login {
            pointer-events: auto;
            /* ให้คลิกปุ่มได้ */
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 1rem 3rem;
            font-size: 1.25rem;
            font-weight: 500;
            color: white;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 50px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            cursor: pointer;
        }

        .btn-login:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        /* Interaction hint */
        .interaction-hint {
            position: absolute;
            bottom: 20%;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
            pointer-events: none;
            animation: pulse 2s infinite;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Animations */
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0% {
                opacity: 0.4;
            }

            50% {
                opacity: 0.8;
            }

            100% {
                opacity: 0.4;
            }
        }

        /* Loading Screen */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #111;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }

        .globe-spinner {
            width: 80px;
            height: 80px;
            animation: spinGlobe 2s linear infinite;
        }

        @keyframes spinGlobe {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .loading-text {
            margin-top: 20px;
            font-size: 1.2rem;
            letter-spacing: 1px;
            animation: pulseText 1.5s infinite;
        }

        @keyframes pulseText {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        @media (max-width: 768px) {
            .header-content h1 {
                font-size: 2.5rem;
            }

            .header-content p {
                font-size: 1rem;
            }

            .btn-login {
                padding: 0.8rem 2.5rem;
                font-size: 1.1rem;
            }

            .ui-layer {
                padding: 1.5rem;
            }
        }

        /* --- Theme Toggle Button --- */
        .theme-toggle-btn {
            position: fixed;
            top: 2rem;
            right: 2rem;
            z-index: 100;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .theme-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        .theme-toggle-btn.loading {
            opacity: 0.7;
            cursor: not-allowed;
            pointer-events: none;
            animation: spin 2s linear infinite;
        }

        @keyframes spin {
            100% { transform: rotate(360deg); }
        }

        .theme-icon {
            width: 24px;
            height: 24px;
            transition: all 0.3s ease;
        }

        .theme-icon.hidden {
            display: none;
        }
    </style>
</head>

<body>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay">
        <svg class="globe-spinner" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="2" y1="12" x2="22" y2="12"></line>
            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
        </svg>
        <div class="loading-text">กำลังโหลดโลกจำลอง...</div>
    </div>

    <!-- 3D Model Background -->
    <div id="model-container">
        <!-- 
            src: URL ของไฟล์ .glb หรือ .gltf
            environment-image: แสงเงาของโมเดล (optional)
            camera-controls: ให้ผู้ใช้ใช้เมาส์หมุน/ซูมได้
        -->
        <model-viewer id="interactive-model" src="model/models/Room_Portfolio.glb" alt="UP Net Zero 3D Model"
            loading="eager" camera-orbit="42.3deg 74deg 26.26m" camera-target="0.46m 1.97m -0.83m" field-of-view="35deg"
            min-camera-orbit="0deg 0deg 5m" max-camera-orbit="90deg 90deg 45m" interaction-prompt="none"
            shadow-intensity="1" environment-image="neutral" exposure="<?= $isNight ? '2.0' : '1.2' ?>"
            style="cursor: pointer;">
        </model-viewer>
    </div>

    <!-- Theme Toggle Button -->
    <button id="theme-toggle" class="theme-toggle-btn" aria-label="Toggle Day/Night Theme">
        <!-- Sun Icon (shown in day mode) -->
        <svg id="icon-sun" class="theme-icon <?= $isNight ? 'hidden' : '' ?>" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5"></circle>
            <line x1="12" y1="1" x2="12" y2="3"></line>
            <line x1="12" y1="21" x2="12" y2="23"></line>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
            <line x1="1" y1="12" x2="3" y2="12"></line>
            <line x1="21" y1="12" x2="23" y2="12"></line>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        </svg>
        <!-- Moon Icon (shown in night mode) -->
        <svg id="icon-moon" class="theme-icon <?= $isNight ? '' : 'hidden' ?>" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
        </svg>
    </button>

    <!-- UI Overlay Layer -->
    <div class="ui-layer">

        <div class="header-content">
            <h1>UP NET ZERO</h1>
            <p>มหาวิทยาลัยพะเยา มุ่งสู่ความเป็นกลางทางคาร์บอน</p>
        </div>

        <div class="interaction-hint">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12a10 10 0 1 0 20 0 10 10 0 1 0-20 0"></path>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z">
                </path>
                <path d="M2 12h20"></path>
            </svg>
            ลองหมุนโมเดล 3 มิติ
        </div>

        <div class="bottom-action">
            <a href="login.php" class="btn-login">
                เข้าสู่ระบบ (Login)
                <svg style="margin-left: 10px;" width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
        </div>

    </div>

    <script type="module">
        import * as THREE from 'https://unpkg.com/three@0.158.0/build/three.module.js';

        // เปิด Cache และเริ่มโหลด Texture ทันที (พร้อมกับโมเดล)
        THREE.Cache.enabled = true;
        const _earlyLoader = new THREE.TextureLoader();
        const _isNightInit = <?= $isNight ? 'true' : 'false' ?>;
        const _timeFolderInit = _isNightInit ? 'night' : 'day';
        const _suffixInit    = _isNightInit ? '_night' : '_day';
        const _texturePromise = Promise.all([
            _earlyLoader.loadAsync(`model/textures/room/${_timeFolderInit}/first_texture_set${_suffixInit}.webp`),
            _earlyLoader.loadAsync(`model/textures/room/${_timeFolderInit}/second_texture_set${_suffixInit}.webp`),
            _earlyLoader.loadAsync(`model/textures/room/${_timeFolderInit}/third_texture_set${_suffixInit}.webp`),
            _earlyLoader.loadAsync(`model/textures/room/${_timeFolderInit}/fourth_texture_set${_suffixInit}.webp`),
        ]);

        const modelViewer = document.getElementById('interactive-model');
        let startX, startY;

        // บันทึกตำแหน่งตอนเริ่มคลิกเมาส์
        modelViewer.addEventListener('pointerdown', (e) => {
            startX = e.clientX;
            startY = e.clientY;
        });

        // (ส่วนตรวจสอบ pointerup ย้ายไปไว้ด้านในเพื่อให้ใช้ตัวแปร currentHoveredObject ได้)

        // ฟังก์ชันสำหรับจัดการโมเดลเมื่อโหลดเสร็จ
        modelViewer.addEventListener('load', () => {
            // เข้าถึง Scene ของ Three.js ที่ซ่อนอยู่ใน model-viewer
            const symbols = Object.getOwnPropertySymbols(modelViewer);
            const sceneSymbol = symbols.find(s => s.description === 'scene');

            if (sceneSymbol && modelViewer[sceneSymbol]) {
                const scene = modelViewer[sceneSymbol];
                const animObjects = {};

                const xAxisFans = [];
                const yAxisFans = [];
                let chairTop = null;
                let fish = null;
                const raycasterObjects = [];

                // ใช้ TextureLoader ตัวเดิม (Cache เปิดแล้วตั้งแต่ต้น)
                const textureLoader = _earlyLoader;
                let currentIsNight = _isNightInit;

                // สร้าง Material ล่วงหน้า เพื่อเอาไปสลับ Map ทีหลังได้
                const matFirst = new THREE.MeshBasicMaterial();
                const matSecond = new THREE.MeshBasicMaterial();
                const matThird = new THREE.MeshBasicMaterial();
                const matFourth = new THREE.MeshBasicMaterial();

                function applyTextures([tex1, tex2, tex3, tex4], isNight) {
                    [tex1, tex2, tex3, tex4].forEach(tex => {
                        tex.colorSpace = THREE.SRGBColorSpace;
                        tex.flipY = false;
                    });
                    matFirst.map  = tex1; matFirst.needsUpdate  = true;
                    matSecond.map = tex2; matSecond.needsUpdate = true;
                    matThird.map  = tex3; matThird.needsUpdate  = true;
                    matFourth.map = tex4; matFourth.needsUpdate = true;
                    modelViewer.exposure = isNight ? 2.0 : 1.2;
                }

                function loadAndApplyTextures(isNight) {
                    const timeFolder    = isNight ? 'night' : 'day';
                    const textureSuffix = isNight ? '_night' : '_day';
                    const themeToggleBtn = document.getElementById('theme-toggle');
                    if (themeToggleBtn) themeToggleBtn.classList.add('loading');

                    return Promise.all([
                        textureLoader.loadAsync(`model/textures/room/${timeFolder}/first_texture_set${textureSuffix}.webp`),
                        textureLoader.loadAsync(`model/textures/room/${timeFolder}/second_texture_set${textureSuffix}.webp`),
                        textureLoader.loadAsync(`model/textures/room/${timeFolder}/third_texture_set${textureSuffix}.webp`),
                        textureLoader.loadAsync(`model/textures/room/${timeFolder}/fourth_texture_set${textureSuffix}.webp`),
                    ]).then(textures => {
                        applyTextures(textures, isNight);
                        if (themeToggleBtn) themeToggleBtn.classList.remove('loading');
                    });
                }

                // Setup Toggle Button
                const themeToggleBtn = document.getElementById('theme-toggle');
                const iconSun = document.getElementById('icon-sun');
                const iconMoon = document.getElementById('icon-moon');

                if (themeToggleBtn) {
                    themeToggleBtn.addEventListener('click', () => {
                        if (themeToggleBtn.classList.contains('loading')) return;
                        
                        currentIsNight = !currentIsNight;
                        
                        // สลับ Icon
                        if (currentIsNight) {
                            iconSun.classList.add('hidden');
                            iconMoon.classList.remove('hidden');
                        } else {
                            iconSun.classList.remove('hidden');
                            iconMoon.classList.add('hidden');
                        }

                        // โหลดและเปลี่ยน Textures
                        loadAndApplyTextures(currentIsNight);
                    });
                }

                // รอ Texture ที่โหลดพร้อมกับโมเดล (ถ้าเสร็จแล้วก็ไม่ต้องโหลดใหม่)
                _texturePromise.then(textures => {
                    applyTextures(textures, currentIsNight);
                }).then(() => {
                    // วนลูปหาโมเดลย่อย (Meshes)
                    scene.traverse((child) => {
                        if (child.isMesh) {
                            let node = child;
                            let materialAssigned = false;

                            // ตรวจสอบชื่อของตัวเองและ Parent ว่ามีคำว่า First, Second, Third, Fourth ไหม
                            while (node) {
                                const name = node.name || '';
                                if (name.includes('First')) { child.material = matFirst; materialAssigned = true; break; }
                                if (name.includes('Second')) { child.material = matSecond; materialAssigned = true; break; }
                                if (name.includes('Third')) { child.material = matThird; materialAssigned = true; break; }
                                if (name.includes('Fourth')) { child.material = matFourth; materialAssigned = true; break; }
                                node = node.parent;
                            }

                            // ถ้าไม่ตรงกับชื่อไหนเลย ให้ลองตรวจสอบจากลำดับ (เผื่อไว้)
                            if (!materialAssigned && child.parent) {
                                const parentName = child.parent.name;
                                if (parentName === 'First') child.material = matFirst;
                                else if (parentName === 'Second') child.material = matSecond;
                                else if (parentName === 'Third') child.material = matThird;
                                else if (parentName === 'Fourth') child.material = matFourth;
                            }

                            // เก็บชิ้นส่วนสำหรับ Animation เคลื่อนไหวต่อเนื่อง
                            if (child.name.includes("Fan")) {
                                if (child.name.includes("Fan_2") || child.name.includes("Fan_4")) {
                                    xAxisFans.push(child);
                                } else {
                                    yAxisFans.push(child);
                                }
                            }
                            if (child.name.includes("Chair_Top")) {
                                chairTop = child;
                                child.userData.initialRotation = child.rotation.clone();
                            }
                            if (child.name.includes("Fish_Fourth")) {
                                fish = child;
                                if (!child.userData.initialPosition) child.userData.initialPosition = child.position.clone();
                            }

                            // เก็บข้อมูลไว้สำหรับ Hover Effect ก่อนที่จะถูกแก้ Scale เป็น 0
                            if (child.name.includes("Hover") || child.name.includes("Key")) {
                                if (!child.userData.initialScale) child.userData.initialScale = child.scale.clone();
                                if (!child.userData.initialPosition) child.userData.initialPosition = child.position.clone();
                                if (!child.userData.initialRotation) child.userData.initialRotation = child.rotation.clone();
                                raycasterObjects.push(child);
                            }

                            // เตรียม Animation แบบเดียวกับ R2
                            const animNames = [
                                "Hanging_Plank_1", "Hanging_Plank_2", "My_Work_Button", "About_Button", "Contact_Button",
                                "Boba", "GitHub", "YouTube", "Twitter",
                                "Name_Letter_1", "Name_Letter_2", "Name_Letter_3", "Name_Letter_4", "Name_Letter_5", "Name_Letter_6", "Name_Letter_7", "Name_Letter_8",
                                "Flower_1", "Flower_2", "Flower_3", "Flower_4", "Flower_5",
                                "Box_1", "Box_2", "Box_3", "Lamp", "Slipper_1", "Slipper_2", "Fish_Fourth",
                                "Egg_1", "Egg_2", "Egg_3", "Frame_1", "Frame_2", "Frame_3"
                            ];

                            animNames.forEach(name => {
                                if (child.name.includes(name)) {
                                    animObjects[name] = child;
                                    if (!child.userData.initialPosition) child.userData.initialPosition = child.position.clone();
                                    if (name === "Hanging_Plank_1") {
                                        child.scale.set(0, 0, 1);
                                    } else {
                                        child.scale.set(0, 0, 0);
                                    }
                                }
                            });

                            // Piano keys
                            if (child.name.includes("_Key")) {
                                animObjects[child.name] = child;
                                if (!child.userData.initialPosition) child.userData.initialPosition = child.position.clone();
                                child.scale.set(0, 0, 0);
                            }
                        }
                    });

                    // เล่น Animation แบบ GSAP เหมือน R2
                    function playIntroAnimation() {
                        if (typeof gsap === 'undefined') return;

                        const t1 = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.8)" } });
                        t1.timeScale(0.8);

                        if (animObjects["Hanging_Plank_1"]) t1.to(animObjects["Hanging_Plank_1"].scale, { x: 1, y: 1 });
                        if (animObjects["Hanging_Plank_2"]) t1.to(animObjects["Hanging_Plank_2"].scale, { x: 1, y: 1, z: 1 }, "-=0.5");

                        const buttons = ["My_Work_Button", "About_Button", "Contact_Button"];
                        buttons.forEach(btn => {
                            if (animObjects[btn]) t1.to(animObjects[btn].scale, { x: 1, y: 1, z: 1 }, "-=0.6");
                        });

                        const tFrames = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.8)" } });
                        tFrames.timeScale(0.8);
                        ["Frame_1", "Frame_2", "Frame_3"].forEach((frame, i) => {
                            if (animObjects[frame]) tFrames.to(animObjects[frame].scale, { x: 1, y: 1, z: 1 }, i === 0 ? "" : "-=0.5");
                        });

                        const t2 = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.8)" } });
                        t2.timeScale(0.8);
                        if (animObjects["Boba"]) t2.to(animObjects["Boba"].scale, { x: 1, y: 1, z: 1, delay: 0.4 });
                        ["GitHub", "YouTube", "Twitter"].forEach(social => {
                            if (animObjects[social]) t2.to(animObjects[social].scale, { x: 1, y: 1, z: 1 }, "-=0.5");
                        });

                        const tFlowers = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.8)" } });
                        tFlowers.timeScale(0.8);
                        ["Flower_5", "Flower_4", "Flower_3", "Flower_2", "Flower_1"].forEach((flower, i) => {
                            if (animObjects[flower]) tFlowers.to(animObjects[flower].scale, { x: 1, y: 1, z: 1 }, i === 0 ? "" : "-=0.5");
                        });

                        const tBoxes = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.8)" } });
                        tBoxes.timeScale(0.8);
                        ["Box_1", "Box_2", "Box_3"].forEach((box, i) => {
                            if (animObjects[box]) tBoxes.to(animObjects[box].scale, { x: 1, y: 1, z: 1 }, i === 0 ? "" : "-=0.5");
                        });

                        if (animObjects["Lamp"]) {
                            gsap.to(animObjects["Lamp"].scale, { x: 1, y: 1, z: 1, duration: 0.8, delay: 0.2, ease: "back.out(1.8)" });
                        }

                        const tSlippers = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.8)" } });
                        tSlippers.timeScale(0.8);
                        if (animObjects["Slipper_1"]) tSlippers.to(animObjects["Slipper_1"].scale, { x: 1, y: 1, z: 1, delay: 0.5 });
                        if (animObjects["Slipper_2"]) tSlippers.to(animObjects["Slipper_2"].scale, { x: 1, y: 1, z: 1 }, "-=0.5");

                        const tEggs = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.8)" } });
                        tEggs.timeScale(0.8);
                        ["Egg_1", "Egg_2", "Egg_3"].forEach((egg, i) => {
                            if (animObjects[egg]) tEggs.to(animObjects[egg].scale, { x: 1, y: 1, z: 1 }, i === 0 ? "" : "-=0.5");
                        });

                        if (animObjects["Fish_Fourth"]) {
                            gsap.to(animObjects["Fish_Fourth"].scale, { x: 1, y: 1, z: 1, duration: 0.8, delay: 0.8, ease: "back.out(1.8)" });
                        }

                        const lettersTl = gsap.timeline({ defaults: { duration: 0.8, ease: "back.out(1.7)" } });
                        lettersTl.timeScale(0.8);
                        for (let i = 1; i <= 8; i++) {
                            const letter = animObjects[`Name_Letter_${i}`];
                            if (letter) {
                                lettersTl.to(letter.position, { y: letter.userData.initialPosition.y + 0.3, duration: 0.4, ease: "back.out(1.8)", delay: i === 1 ? 0.25 : 0 }, i === 1 ? "" : "-=0.5")
                                    .to(letter.scale, { x: 1, y: 1, z: 1, duration: 0.4, ease: "back.out(1.8)" }, "<")
                                    .to(letter.position, { y: letter.userData.initialPosition.y, duration: 0.4, ease: "back.out(1.8)" }, ">-0.2");
                            }
                        }

                        const pianoKeysTl = gsap.timeline({ defaults: { duration: 0.4, ease: "back.out(1.7)" } });
                        pianoKeysTl.timeScale(1.2);
                        let keyIndex = 0;
                        Object.values(animObjects).forEach(obj => {
                            if (obj.name && obj.name.includes("_Key")) {
                                pianoKeysTl.to(obj.position, { y: obj.userData.initialPosition.y + 0.2, duration: 0.4, ease: "back.out(1.8)" }, keyIndex * 0.1)
                                    .to(obj.scale, { x: 1, y: 1, z: 1, duration: 0.4, ease: "back.out(1.8)" }, "<")
                                    .to(obj.position, { y: obj.userData.initialPosition.y, duration: 0.4, ease: "back.out(1.8)" }, ">-0.2");
                                keyIndex++;
                            }
                        });
                    }

                    // --- ตั้งค่าระบบขยับ Hover และ Animation ตลอดเวลา ---
                    let camera = scene.camera;
                    if (!camera) {
                        const syms = Object.getOwnPropertySymbols(modelViewer);
                        syms.forEach(sym => {
                            if (modelViewer[sym] && modelViewer[sym].isPerspectiveCamera) camera = modelViewer[sym];
                            if (modelViewer[sym] && modelViewer[sym].camera && modelViewer[sym].camera.isPerspectiveCamera) camera = modelViewer[sym].camera;
                        });
                    }

                    const raycaster = new THREE.Raycaster();
                    const pointer = new THREE.Vector2(-100, -100);
                    let currentHoveredObject = null;

                    window.addEventListener("mousemove", (e) => {
                        const rect = modelViewer.getBoundingClientRect();
                        pointer.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                        pointer.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
                    });

                    modelViewer.addEventListener('pointerup', (e) => {
                        const diffX = Math.abs(e.clientX - startX);
                        const diffY = Math.abs(e.clientY - startY);
                        if (diffX < 5 && diffY < 5 && currentHoveredObject) {
                            // window.location.href = 'login.php'; // คลิกชิ้นส่วนแล้วไปยังหน้า login (ปิดตามคำขอ)
                        }
                    });

                    function playHoverAnimation(object, isHovering) {
                        if (typeof gsap === 'undefined') return;
                        let scale = 1.4;
                        gsap.killTweensOf(object.scale);
                        gsap.killTweensOf(object.rotation);
                        gsap.killTweensOf(object.position);

                        if (object.name.includes("Fish")) scale = 1.2;

                        if (isHovering) {
                            gsap.to(object.scale, {
                                x: object.userData.initialScale.x * scale,
                                y: object.userData.initialScale.y * scale,
                                z: object.userData.initialScale.z * scale,
                                duration: 0.5, ease: "back.out(2)"
                            });
                            if (object.name.includes("About_Button")) {
                                gsap.to(object.rotation, { x: object.userData.initialRotation.x - Math.PI / 10, duration: 0.5, ease: "back.out(2)" });
                            } else if (object.name.includes("Button") || object.name.includes("GitHub") || object.name.includes("YouTube") || object.name.includes("Twitter")) {
                                gsap.to(object.rotation, { x: object.userData.initialRotation.x + Math.PI / 10, duration: 0.5, ease: "back.out(2)" });
                            }
                            if (object.name.includes("Boba") || object.name.includes("Name_Letter")) {
                                gsap.to(object.position, { y: object.userData.initialPosition.y + 0.2, duration: 0.5, ease: "back.out(2)" });
                            }
                        } else {
                            gsap.to(object.scale, {
                                x: object.userData.initialScale.x,
                                y: object.userData.initialScale.y,
                                z: object.userData.initialScale.z,
                                duration: 0.3, ease: "back.out(2)"
                            });
                            gsap.to(object.rotation, { x: object.userData.initialRotation.x, duration: 0.3, ease: "back.out(2)" });
                            gsap.to(object.position, { y: object.userData.initialPosition.y, duration: 0.3, ease: "back.out(2)" });
                        }
                    }

                    const clock = new THREE.Clock();
                    let toggleOrbit = false;
                    let lastTime = 0;

                    const renderLoop = () => {
                        const now = performance.now();
                        if (now - lastTime < 50) return; // จำกัด ~20 FPS
                        lastTime = now;

                        const elapsedTime = clock.getElapsedTime();

                        // Fan rotate
                        xAxisFans.forEach((fan) => { fan.rotation.x -= 0.04; });
                        yAxisFans.forEach((fan) => { fan.rotation.y -= 0.04; });

                        // Chair rotate
                        if (chairTop) {
                            const time = elapsedTime;
                            const baseAmplitude = Math.PI / 8;
                            const rotationOffset = baseAmplitude * Math.sin(time * 0.5) * (1 - Math.abs(Math.sin(time * 0.5)) * 0.3);
                            chairTop.rotation.y = chairTop.userData.initialRotation.y + rotationOffset;
                        }

                        // Fish up and down
                        if (fish) {
                            const time = elapsedTime * 1.5;
                            const amplitude = 0.12;
                            const position = amplitude * Math.sin(time) * (1 - Math.abs(Math.sin(time)) * 0.1);
                            fish.position.y = fish.userData.initialPosition.y + position;
                        }

                        // Raycaster สำหรับ Hover Effect
                        if (camera && raycasterObjects.length > 0) {
                            raycaster.setFromCamera(pointer, camera);
                            const intersects = raycaster.intersectObjects(raycasterObjects, true);

                            if (intersects.length > 0) {
                                let obj = intersects[0].object;
                                while (obj && !obj.userData.initialScale && obj.parent) {
                                    obj = obj.parent;
                                }

                                if (obj && obj.userData.initialScale) {
                                    if (obj !== currentHoveredObject) {
                                        if (currentHoveredObject) playHoverAnimation(currentHoveredObject, false);
                                        currentHoveredObject = obj;
                                        playHoverAnimation(obj, true);
                                    }
                                    document.body.style.cursor = "pointer";
                                }
                            } else {
                                if (currentHoveredObject) {
                                    playHoverAnimation(currentHoveredObject, false);
                                    currentHoveredObject = null;
                                }
                                document.body.style.cursor = "default";
                            }
                        }

                        // บังคับ Render ตลอดเวลาเพื่อให้เห็น Animation ทำงาน
                        modelViewer.cameraOrbit = toggleOrbit ? "42.3deg 74deg 26.2601m" : "42.3deg 74deg 26.26m";
                        toggleOrbit = !toggleOrbit;
                    };

                    if (typeof gsap !== 'undefined') {
                        gsap.ticker.add(renderLoop);
                    }

                    // ให้เวลาการ์ดจอ (GPU) ประมวลผลและสวมสีลงบนโมเดลให้เสร็จก่อน (Shader Compilation)
                    // ปิดหน้าจอ Loading Overlay ทันทีโดยไม่ต้องรอ
                    const loadingOverlay = document.getElementById('loading-overlay');
                    if (loadingOverlay) {
                        loadingOverlay.style.opacity = '0';
                        loadingOverlay.style.visibility = 'hidden';
                    }

                    playIntroAnimation();
                }); // ปิด Promise.all ของ TextureLoader
            }
        });
    </script>

</body>

</html>