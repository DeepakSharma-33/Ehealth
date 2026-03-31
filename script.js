// Add smooth scrolling and interactive effects
        document.addEventListener('DOMContentLoaded', function() {

            // ── Handwriting loader animation ──────────────────────────────
            const loaderTitle = document.getElementById('loaderTitle');
            if (loaderTitle) {
                const fullText = loaderTitle.dataset.text || 'SRMS EHEALTH';
                const brand = loaderTitle.closest('.loader-brand');

                // Clear original text
                loaderTitle.innerHTML = '';
                loaderTitle.classList.add('loader-title');

                // Add underline and subtitle to brand container
                const line = document.createElement('div');
                line.className = 'loader-underline';
                brand.appendChild(line);

                const sub = document.createElement('div');
                sub.className = 'loader-subtitle';
                sub.textContent = 'Telemedicine Platform';
                brand.appendChild(sub);

                // Ink cursor dot inside title
                const cursor = document.createElement('span');
                cursor.className = 'ink-cursor';
                loaderTitle.appendChild(cursor);

                // Build one span per character
                const chars = [];
                [...fullText].forEach((ch) => {
                    const span = document.createElement('span');
                    span.className = 'loader-char';
                    span.textContent = ch === ' ' ? '\u00A0' : ch;
                    loaderTitle.insertBefore(span, cursor);
                    chars.push(span);
                });

                // Stagger reveal with natural timing variation
                let delay = 150;
                chars.forEach((span, i) => {
                    const isSpace = fullText[i] === ' ';
                    const charDelay = isSpace ? 120 : 55 + Math.random() * 25;

                    setTimeout(() => {
                        span.classList.add('visible', 'written');

                        // Move ink cursor to right edge of this char
                        const rect = span.getBoundingClientRect();
                        const titleRect = loaderTitle.getBoundingClientRect();
                        cursor.style.opacity = '1';
                        cursor.style.left = (rect.right - titleRect.left + 4) + 'px';

                        // After last letter: hide cursor, draw underline, show subtitle
                        if (i === chars.length - 1) {
                            setTimeout(() => {
                                cursor.style.opacity = '0';
                                line.style.width = '100%';
                                setTimeout(() => { sub.style.opacity = '1'; }, 300);
                            }, 200);
                        }
                    }, delay);

                    delay += charDelay;
                });
            }

            // Add click effects to cards
            const cards = document.querySelectorAll('.user-card');
            cards.forEach(card => {
                card.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Add clicking effect
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = 'translateY(-10px)';
                        // Navigate to the page
                        window.location.href = this.href;
                    }, 150);
                });
            });

            // Add hover effect to navigation
            const navLinks = document.querySelectorAll('.nav-menu a');
            navLinks.forEach(link => {
                link.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                link.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Mobile menu functionality
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
            const navMenu = document.querySelector('.nav-menu');
            
            mobileMenuBtn.addEventListener('click', function() {
                if (navMenu.style.display === 'flex') {
                    navMenu.style.display = 'none';
                } else {
                    navMenu.style.display = 'flex';
                    navMenu.style.flexDirection = 'column';
                    navMenu.style.position = 'absolute';
                    navMenu.style.top = '100%';
                    navMenu.style.right = '0';
                    navMenu.style.background = 'rgba(76, 175, 80, 0.95)';
                    navMenu.style.padding = '1rem';
                    navMenu.style.borderRadius = '10px';
                    navMenu.style.backdropFilter = 'blur(10px)';
                }
            });

            // Add intersection observer for animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.animation = entry.target.dataset.animation || 'fadeInUp 0.8s ease-out forwards';
                    }
                });
            }, observerOptions);

            // Observe elements for animation
            const animatedElements = document.querySelectorAll('.user-card, .feature-item');
            animatedElements.forEach(el => observer.observe(el));

            // Add parallax effect to background
            window.addEventListener('scroll', function() {
                const scrolled = window.pageYOffset;
                const rate = scrolled * -0.5;
                document.body.style.backgroundPosition = `center ${rate}px`;
            });

            // Add typing effect to main heading
            const heading = document.querySelector('.main-heading');
            const text = heading.textContent;
            heading.textContent = '';
            
            let i = 0;
            const typeWriter = () => {
                if (i < text.length) {
                    heading.textContent += text.charAt(i);
                    i++;
                    setTimeout(typeWriter, 100);
                }
            };
            
            setTimeout(typeWriter, 3800); // Start after loader fully fades
        });

        // Add smooth page transitions
        window.addEventListener('beforeunload', function() {
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.3s ease-out';
        });

        // Restore visibility when coming back via browser back/forward cache
        window.addEventListener('pageshow', function() {
            document.body.style.opacity = '1';
            document.body.style.transition = '';
        });
