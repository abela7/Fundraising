<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liverpool Abune Teklehaymanot EOTC - Building Our Legacy Church</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts for Amharic support -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;700;900&display=swap" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    
    <style>
        :root {
            --primary-bg: #1e4d5c;
            --accent-gold: #ffd700;
            --text-white: #ffffff;
            --text-light: #e8f4f8;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Noto Sans Ethiopic', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, #2a6b7d 100%);
            color: var(--text-white);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem 1rem;
            position: relative;
            background: radial-gradient(circle at center, rgba(255, 255, 255, 0.05) 0%, transparent 70%);
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="0.5" fill="%23ffffff" opacity="0.03"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            pointer-events: none;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 800px;
        }
        
        .amharic-quote {
            font-size: 1.1rem;
            color: var(--accent-gold);
            margin-bottom: 1.5rem;
            font-weight: 400;
            line-height: 1.8;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .church-name {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 2rem;
            text-shadow: var(--shadow);
            letter-spacing: 1px;
        }
        
        .core-message {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 3rem;
            text-shadow: var(--shadow);
            line-height: 1.2;
        }
        
        .core-message .amharic {
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .core-message .english {
            font-size: 2.8rem;
            opacity: 0.95;
        }
        
        /* Vision Description */
        .vision-description {
            max-width: 900px;
            margin: 0 auto;
            text-align: left;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .amharic-heading {
            font-size: 1.4rem;
            color: var(--accent-gold);
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: 700;
            line-height: 1.6;
        }
        
        .amharic-text {
            font-size: 1.1rem;
            color: var(--text-light);
            margin-bottom: 1.2rem;
            line-height: 1.8;
            text-align: justify;
        }
        
        .amharic-text:last-child {
            margin-bottom: 0;
            font-weight: 600;
            color: var(--accent-gold);
            text-align: center;
            font-size: 1.2rem;
        }
        
        /* Floor Plan Section */
        .floor-plan {
            padding: 4rem 2rem 2rem 2rem;
        }
        
        .floor-plan h2 {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 3rem;
            color: var(--accent-gold);
            text-shadow: var(--shadow);
        }
        
        /* Church Floor Map Section */
        .floor-map-section {
            margin-bottom: 4rem;
            padding: 0 1rem;
        }
        
        .floor-map-container {
            max-width: 95%;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .floor-map-image {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .floor-map-img {
            max-width: 100%;
            height: auto;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            border: 2px solid var(--accent-gold);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .floor-map-img:hover {
            transform: scale(1.02);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.4);
        }
        
        .floor-map-info {
            text-align: center;
        }
        
        .floor-map-title {
            font-size: 1.8rem;
            color: var(--accent-gold);
            margin-bottom: 1.5rem;
            font-weight: 700;
        }
        
        .floor-map-description {
            font-size: 1.1rem;
            color: var(--text-light);
            margin-bottom: 1rem;
            line-height: 1.6;
        }
        
        .floor-map-description:last-child {
            margin-bottom: 0;
        }
        
        /* Lightbox Gallery */
        .lightbox {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(15px);
        }
        
        .lightbox-content {
            position: relative;
            margin: auto;
            padding: 20px;
            width: 90%;
            max-width: 1200px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .lightbox-img {
            max-width: 100%;
            max-height: 90vh;
            border-radius: 10px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
        }
        
        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: var(--text-white);
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10000;
            width: 50px;
            height: 50px;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .lightbox-close:hover {
            background: var(--accent-gold);
            color: #1a365d;
            transform: scale(1.1);
        }
        
        /* Grid System Explanation */
        .grid-explanation {
            margin-bottom: 2rem;
        }
        
        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .grid-item {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            border: 2px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        
        .grid-item:hover {
            transform: translateY(-3px);
            border-color: var(--accent-gold);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .grid-visual {
            margin-bottom: 1rem;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100px;
        }
        
        .grid-square {
            border: 2px solid var(--accent-gold);
            background: rgba(255, 255, 255, 0.05);
            position: relative;
            display: grid;
            gap: 1px;
            padding: 1px;
        }
        
        .large-square {
            width: 100px;
            height: 100px;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
        }
        
        .medium-square {
            width: 100px;
            height: 100px;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
        }
        
        .small-square {
            width: 100px;
            height: 100px;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
        }
        
        .sub-square {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 3px;
            transition: all 0.3s ease;
        }
        
        .sub-square.filled {
            background: linear-gradient(45deg, var(--accent-gold), #ffed4e);
            border: 1px solid var(--accent-gold);
        }
        
        .grid-item:hover .sub-square.filled {
            background: linear-gradient(45deg, #ffed4e, var(--accent-gold));
        }
        
        .grid-label {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent-gold);
            margin-bottom: 0.8rem;
        }
        
        .grid-description {
            font-size: 1.1rem;
            color: var(--text-light);
            opacity: 0.95;
            line-height: 1.4;
        }
        
        .grid-dimensions {
            font-size: 1rem;
            color: var(--accent-gold);
            opacity: 0.9;
            font-weight: 600;
            margin-top: 0.8rem;
        }
        

        
        /* Call to Action Section */
        .cta-section {
            padding: 4rem 2rem;
            text-align: center;
        }
        
        .cta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .cta-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 2.5rem;
            font-size: 1.3rem;
            font-weight: 700;
            text-decoration: none;
            color: #1a365d;
            background: linear-gradient(135deg, var(--accent-gold), #ffed4e);
            border-radius: 20px;
            transition: all 0.3s ease;
            border: 2px solid var(--accent-gold);
            cursor: pointer;
            min-height: 65px;
            gap: 0.8rem;
        }
        
        .cta-button:hover {
            transform: translateY(-2px);
            color: #1a365d;
            text-decoration: none;
            background: linear-gradient(135deg, #ffed4e, var(--accent-gold));
        }
        
        .cta-button.secondary {
            background: rgba(255, 255, 255, 0.15);
            border: 2px solid rgba(255, 255, 255, 0.4);
            color: var(--text-white);
            backdrop-filter: blur(10px);
        }
        
        .cta-button.secondary:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: var(--accent-gold);
            color: var(--text-white);
            transform: translateY(-2px);
        }
        
        .cta-button i {
            margin-right: 0.8rem;
            font-size: 1.3rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .hero {
                padding: 1rem;
            }
            
            .amharic-quote {
                font-size: 1rem;
            }
            
            .church-name {
                font-size: 2rem;
            }
            
            .core-message {
                font-size: 2.5rem;
            }
            
            .core-message .english {
                font-size: 2rem;
            }
            
            .vision-description {
                padding: 1.5rem;
                margin: 0 1rem;
            }
            
            .amharic-heading {
                font-size: 1.3rem;
            }
            
            .amharic-text {
                font-size: 1.1rem;
            }
            
            .floor-plan h2 {
                font-size: 2rem;
            }
            
            .floor-map-container {
                padding: 1rem 0.3rem;
                margin: 0 0.1rem;
                max-width: 98%;
            }
            
            .floor-map-img {
                border-radius: 10px;
                margin: 0 -0.3rem;
                max-width: calc(100% + 0.6rem);
            }
            
            .floor-map-title {
                font-size: 1.5rem;
            }
            
            .floor-map-description {
                font-size: 1rem;
            }
            
            .grid-container {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .grid-label {
                font-size: 1.4rem;
            }
            
            .grid-description {
                font-size: 1.2rem;
            }
            
            .grid-dimensions {
                font-size: 1.1rem;
            }
            
            .cta-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
            
            .cta-button {
                padding: 1.3rem 2rem;
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 480px) {
            .core-message {
                font-size: 2rem;
            }
            
            .core-message .english {
                font-size: 1.6rem;
            }
            
            .vision-description {
                padding: 1rem;
                margin: 0 0.5rem;
            }
            
            .amharic-heading {
                font-size: 1.2rem;
            }
            
            .amharic-text {
                font-size: 1rem;
            }
            
            .floor-map-container {
                padding: 0.8rem 0.1rem;
                margin: 0 0.05rem;
                max-width: 99%;
            }
            
            .floor-map-img {
                border-radius: 8px;
                margin: 0 -0.1rem;
                max-width: calc(100% + 0.2rem);
            }
            
            .floor-map-title {
                font-size: 1.3rem;
            }
            
            .floor-map-description {
                font-size: 0.95rem;
            }
            
            .grid-container {
                gap: 1rem;
            }
            
            .grid-item {
                padding: 1rem;
            }
            
            .grid-label {
                font-size: 1.3rem;
            }
            
            .grid-description {
                font-size: 1.1rem;
            }
            
            .grid-dimensions {
                font-size: 1rem;
            }
            
            .cta-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .cta-button {
                padding: 1.2rem 1.8rem;
                font-size: 1.1rem;
            }
        }
        
        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }
        
        /* Loading animation */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.8s ease forwards;
        }
        
        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in:nth-child(2) { animation-delay: 0.2s; }
        .fade-in:nth-child(3) { animation-delay: 0.4s; }
        .fade-in:nth-child(4) { animation-delay: 0.6s; }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <div class="amharic-quote fade-in">
                "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጁልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ፡፡" <br>፪ ዜና ፪፥፱
            </div>
            
            <h1 class="church-name fade-in">
                LIVERPOOL ABUNE TEKLEHAYMANOT EOTC
            </h1>
            
            <div class="core-message fade-in">
                <span class="amharic">ይህ ታሪኬ ነው</span>
                <span class="english">It is My History</span>
            </div>
            
            <div class="vision-description fade-in">
                <h3 class="amharic-heading">የሊቨርፑል መካነ ቅዱሳን አቡነ ተክለሃይማኖት ቤተ ክርስቲያን ህልም እውን እንዲሆን ያግዙን።</h3>
                <p class="amharic-text">
                    ለትውልድ የሚሻገር ትልቅ አሻራ የምናሳርፍበት፣ መንፈሳዊ አገልግሎታችንን በተሟላ ሁኔታ የምናከናውንበት እና ለልጆቻችን ሃይማኖታችንን የምናስተላልፍበት ሕንጻ ቤተ ክርስቲያን ባለቤት ለመሆን እየተጋን ነው።
                </p>
                <p class="amharic-text">
                    ይህን ታላቅ ምዕራፍ ለመጨረስ ግን የእያንዳንዳችን ድርሻ ወሳኝ ነው። እርስዎም የበኩሎዎን በመወጣት የበረከት ተካፋይ እና የዚህ ታሪካዊ ስኬት አካል እንዲሆኑ በጻድቁ አቡነ ተክለሃይማኖት ስም በአክብሮት እንጠይቃለን።
                </p>
                <p class="amharic-text">
                    በካሬ ዋጋ ድጋፍ ያድርጉ <br> ከታች በተቀመጠው የካሬ ዋጋ መሰረት አቅምዎ የፈቀደውን ድጋፍ ያድርጉልን። ቢያንስ እርስዎ ወይም ወዳጅዎ ቆመው የሚያስቀድሱባትን ቦታ ይግዙልን።
                </p>
            </div>
        </div>
    </section>
    
    <!-- Floor Plan Explanation Section -->
    <section class="floor-plan">

        
                 <!-- Church Floor Map Section -->
         <div class="floor-map-section fade-in">
             <div class="floor-map-container">
                 <div class="floor-map-image">
                     <img src="Floor Map.png" alt="Church Upper Floor Plan" class="floor-map-img" onclick="openLightbox()">
                 </div>
                 <div class="floor-map-info">
                     <h3 class="floor-map-title">የቤተ ክርስቲያን ላይኛ ወለል ካርታ</h3>
                     <p class="floor-map-description">
                         ቤተ ክርስቲያኑ 2 ወለል አለው። እያንዳንዱ ወለል 513m² ስፋት አለው።
                     </p>
                     <p class="floor-map-description">
                         ይህ ላይኛው ወለል ነው። እያንዳንዱ ካሬ 1m × 1m ስፋት አለው እና ዋጋው £400 ነው።
                     </p>
                     <p class="floor-map-description">
                         ግማሹ 1m × 0.5m እና አንድ አራተኛው 0.5m × 0.5m ነው።
                     </p>
                 </div>
             </div>
         </div>
         
         <!-- Grid System Explanation -->
         <div class="grid-explanation fade-in">
             <div class="grid-container">
                 <div class="grid-item large">
                     <div class="grid-visual">
                         <div class="grid-square large-square">
                             <div class="sub-square filled"></div>
                             <div class="sub-square filled"></div>
                             <div class="sub-square filled"></div>
                             <div class="sub-square filled"></div>
                         </div>
                     </div>
                     <div class="grid-label">1m² = £400</div>
                     <div class="grid-description">4 x 0.25m² spaces</div>
                     <div class="grid-dimensions">1m × 1m</div>
                 </div>
                 
                 <div class="grid-item medium">
                     <div class="grid-visual">
                         <div class="grid-square medium-square">
                             <div class="sub-square filled"></div>
                             <div class="sub-square filled"></div>
                             <div class="sub-square"></div>
                             <div class="sub-square"></div>
                         </div>
                     </div>
                     <div class="grid-label">0.5m² = £200</div>
                     <div class="grid-description">2 x 0.25m² spaces</div>
                     <div class="grid-dimensions">1m × 0.5m</div>
                 </div>
                 
                 <div class="grid-item small">
                     <div class="grid-visual">
                         <div class="grid-square small-square">
                             <div class="sub-square filled"></div>
                             <div class="sub-square"></div>
                             <div class="sub-square"></div>
                             <div class="sub-square"></div>
                         </div>
                     </div>
                     <div class="grid-label">0.25m² = £100</div>
                     <div class="grid-description">1 x 0.25m² space</div>
                     <div class="grid-dimensions">0.5m × 0.5m</div>
                 </div>
             </div>
         </div>
    </section>
    
         <!-- Call to Action Section -->
     <section class="cta-section">
         <div class="cta-grid">
             <a href="public/donate/" class="cta-button fade-in">
                 <i class="fas fa-heart"></i>
                 Donate Now
             </a>
             
             <a href="public/projector/" class="cta-button secondary fade-in">
                 <i class="fas fa-chart-line"></i>
                 View Live Progress
             </a>
             
             <a href="public/projector/floor/" class="cta-button secondary fade-in">
                 <i class="fas fa-map"></i>
                 View Church Floor Map
             </a>
             
             <a href="https://abuneteklehaymanot.org/" target="_blank" class="cta-button secondary fade-in">
                 <i class="fas fa-globe"></i>
                 Visit Our Website
             </a>
         </div>
     </section>
    
         <!-- Lightbox Modal -->
     <div id="lightbox" class="lightbox" onclick="closeLightbox()">
         <div class="lightbox-content">
             <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
             <img id="lightbox-img" class="lightbox-img" src="" alt="Church Floor Plan">
         </div>
     </div>

     <!-- Scripts -->
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationDelay = '0s';
                    entry.target.classList.add('fade-in');
                }
            });
        }, observerOptions);
        
        // Observe all fade-in elements
        document.addEventListener('DOMContentLoaded', () => {
            const fadeElements = document.querySelectorAll('.fade-in');
            fadeElements.forEach(el => observer.observe(el));
        });
        
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
                 });
         
         // Lightbox functionality
         function openLightbox() {
             const lightbox = document.getElementById('lightbox');
             const lightboxImg = document.getElementById('lightbox-img');
             const floorMapImg = document.querySelector('.floor-map-img');
             
             lightboxImg.src = floorMapImg.src;
             lightbox.style.display = 'block';
             document.body.style.overflow = 'hidden'; // Prevent background scrolling
         }
         
         function closeLightbox() {
             const lightbox = document.getElementById('lightbox');
             lightbox.style.display = 'none';
             document.body.style.overflow = 'auto'; // Restore scrolling
         }
         
         // Close lightbox with ESC key
         document.addEventListener('keydown', function(event) {
             if (event.key === 'Escape') {
                 closeLightbox();
             }
         });
         
         // Prevent lightbox from closing when clicking on the image
         document.getElementById('lightbox-img').addEventListener('click', function(event) {
             event.stopPropagation();
         });

     </script>
</body>
</html>
