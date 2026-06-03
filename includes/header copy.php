<?php
// Make sure there's NO whitespace or characters before this <?php tag

// Define base URL - adjust this path according to your setup
$base_url = '/deno2';

// Include auth functions if not already included
if (!function_exists('is_logged_in')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Function to generate correct URLs
function getUrl($path = '') {
    global $base_url;
    return $base_url . '/' . ltrim($path, '/');
}

// IMPORTANT: Don't output any HTML here if you need to redirect later
// Move all HTML output after any potential redirects
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Janak Production Management System</title>
    <link rel="stylesheet" href="<?= getUrl('/style.css') ?>" />
    <style>
        /* Reset */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }

        /* Header */
        header {
            background-color: #003366;
            color: white;
            padding: 1rem;
            position: relative;
            z-index: 1000;
        }

        header h1 {
            font-size: 1.5rem;
            text-align: center;
            padding-right: 50px;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: 2px solid white;
            color: white;
            font-size: 1.5rem;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            display: block;
            z-index: 1002;
        }

        .mobile-menu-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* Mobile Backdrop */
        .mobile-menu-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .mobile-menu-backdrop.active {
            display: block;
        }

        /* Navigation */
        nav {
            position: fixed;
            top: 0;
            left: -300px;
            width: 280px;
            height: 100vh;
            background-color: #004080;
            overflow-y: auto;
            transition: left 0.3s ease;
            z-index: 1001;
            padding-top: 80px;
        }

        nav.active {
            left: 0;
        }

        nav ul {
            list-style: none;
        }

        nav > ul > li {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        nav a,
        nav .menu-item {
            display: block;
            padding: 1rem 1.5rem;
            color: white;
            text-decoration: none;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.3s ease;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }

        nav a:hover,
        nav .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* Submenu Styles */
        .has-submenu .menu-item::after {
            content: "▼";
            float: right;
            font-size: 0.8rem;
            transition: transform 0.3s ease;
        }

        .has-submenu.open .menu-item::after {
            transform: rotate(180deg);
        }

        .submenu {
            max-height: 0;
            overflow: hidden;
            background-color: #0050a0;
            transition: max-height 0.3s ease;
        }

        .submenu.open {
            max-height: 300px;
        }

        .submenu li {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .submenu a {
            padding: 0.8rem 2.5rem;
            font-size: 0.9rem;
        }

        .submenu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* Desktop Styles */
        @media (min-width: 769px) {
            header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 1rem 2rem;
            }

            header h1 {
                font-size: 1.6rem;
                text-align: left;
                padding-right: 0;
            }

            .mobile-menu-btn,
            .mobile-menu-backdrop {
                display: none !important;
            }

            nav {
                position: static;
                width: auto;
                height: auto;
                background: none;
                padding-top: 0;
                overflow: visible;
                left: 0;
            }

            nav > ul {
                display: flex;
                gap: 0.5rem;
            }

            nav > ul > li {
                border-bottom: none;
                position: relative;
            }

            nav a,
            nav .menu-item {
                padding: 0.5rem 0.75rem;
                border-radius: 4px;
            }

            .has-submenu:hover .submenu {
                max-height: 400px;
            }

            .submenu {
                position: absolute;
                top: 100%;
                left: 0;
                min-width: 200px;
                background-color: #0050a0;
                border-radius: 4px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                z-index: 1002;
            }

            .submenu a {
                padding: 0.8rem 1rem;
                border-radius: 0;
                white-space: nowrap;
            }

            .submenu li:first-child a {
                border-radius: 4px 4px 0 0;
            }

            .submenu li:last-child {
                border-bottom: none;
            }

            .submenu li:last-child a {
                border-radius: 0 0 4px 4px;
            }
        }

        /* Make sure mobile button is always visible on small screens */
        @media (max-width: 768px) {
            header h1 {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Janak Production Management System</h1>
        <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle navigation">☰</button>
        <div class="mobile-menu-backdrop" id="mobileBackdrop"></div>

        <nav id="mainNav">
            <ul>
                <li><a href="<?= getUrl('index.php') ?>">Dashboard</a></li>
                <li><a href="<?= getUrl('entries/index.php') ?>">Add Deno</a></li>
                
                <!-- Reports Submenu -->
                <li class="has-submenu">
                    <button class="menu-item submenu-trigger" type="button">Reports</button>
                    <ul class="submenu">
                        <li><a href="<?= getUrl('reports/daily.php') ?>">Daily Report</a></li>
                        <li><a href="<?= getUrl('reports/daywisemonth.php') ?>">Day Wise Month</a></li>
                        <li><a href="<?= getUrl('reports/monthly.php') ?>">Monthly Report</a></li>
                        <li><a href="<?= getUrl('reports/books.php') ?>">Books Report</a></li>
                        <li><a href="<?= getUrl('reports/translated.php') ?>">Translated Report</a></li>
                    </ul>
                </li>

                <!-- Modules Submenu -->
                <li class="has-submenu">
                    <button class="menu-item submenu-trigger" type="button">Modules</button>
                    <ul class="submenu">
                        <li><a href="<?= getUrl('jobticket/index.php') ?>">Job Ticket</a></li>
                        <li><a href="<?= getUrl('formaprinting/index.php') ?>">Forma Printing</a></li>
                        <li><a href="<?= getUrl('forma/index.php') ?>">Forma</a></li>
                        <li><a href="<?= getUrl('bookpacking/index.php') ?>">Pack &</a></li>

                    </ul>
                </li>

                <?php if (is_logged_in()): ?>
                    <li><span style="display: block; padding: 1rem 1.5rem; color: white; font-size: 0.9rem;">Welcome, <?= htmlspecialchars($_SESSION['username']) ?> (<?= $_SESSION['user_role'] ?>)</span></li>
                    <?php if (has_role('admin')): ?>
                        <li><a href="<?= getUrl('admin/users.php') ?>">User Management</a></li>
                    <?php endif; ?>
                    <li><a href="<?= getUrl('logout.php') ?>">Logout</a></li>
                <?php else: ?>
                    <li><a href="<?= getUrl('login.php') ?>">Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main>
        <!-- Main content goes here -->
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mainNav = document.getElementById('mainNav');
            const mobileBackdrop = document.getElementById('mobileBackdrop');
            const submenuTriggers = document.querySelectorAll('.submenu-trigger');

            // Toggle mobile menu
            mobileMenuBtn.addEventListener('click', function () {
                const isActive = mainNav.classList.contains('active');
                
                if (isActive) {
                    closeMenu();
                } else {
                    openMenu();
                }
            });

            function openMenu() {
                mainNav.classList.add('active');
                mobileBackdrop.classList.add('active');
                mobileMenuBtn.setAttribute('aria-expanded', 'true');
                document.body.style.overflow = 'hidden'; // Prevent body scroll
            }

            function closeMenu() {
                mainNav.classList.remove('active');
                mobileBackdrop.classList.remove('active');
                mobileMenuBtn.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = ''; // Restore body scroll
                
                // Close all submenus
                document.querySelectorAll('.has-submenu').forEach(item => {
                    item.classList.remove('open');
                    item.querySelector('.submenu').classList.remove('open');
                });
            }

            // Close menu when clicking backdrop
            mobileBackdrop.addEventListener('click', closeMenu);

            // Close menu when clicking regular links (not submenu triggers)
            mainNav.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', closeMenu);
            });

            // Handle submenu toggles
            submenuTriggers.forEach(trigger => {
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    const parentLi = this.parentElement;
                    const submenu = parentLi.querySelector('.submenu');
                    const isOpen = parentLi.classList.contains('open');
                    
                    // Close other submenus first
                    document.querySelectorAll('.has-submenu').forEach(item => {
                        if (item !== parentLi) {
                            item.classList.remove('open');
                            item.querySelector('.submenu').classList.remove('open');
                        }
                    });
                    
                    // Toggle current submenu
                    if (isOpen) {
                        parentLi.classList.remove('open');
                        submenu.classList.remove('open');
                    } else {
                        parentLi.classList.add('open');
                        submenu.classList.add('open');
                    }
                });
            });

            // Close menu when clicking outside
            document.addEventListener('click', function (e) {
                if (!e.target.closest('header') && mainNav.classList.contains('active')) {
                    closeMenu();
                }
            });

            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    closeMenu();
                    document.body.style.overflow = '';
                }
            });
        });
    </script>
</body>
</html>