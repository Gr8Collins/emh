<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responsive Navbar</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary-color: #2563eb;
            --secondary-color: #1e40af;
            --text-color: #333;
            --light-color: #f8fafc;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        body {
            background-color: #f1f5f9;
            color: var(--text-color);
            line-height: 1.6;
        }

        .navbar {
            background-color: white;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 70px;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            text-decoration: none;
        }

        .logo i {
            margin-right: 10px;
        }

        .nav-menu {
            display: flex;
            list-style: none;
            align-items: center;
        }

        .nav-item {
            position: relative;
            margin: 0 5px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            color: var(--text-color);
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .nav-link i {
            margin-right: 8px;
        }

        .dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            background-color: white;
            width: 200px;
            border-radius: 5px;
            box-shadow: var(--shadow);
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.3s ease;
            z-index: 99;
        }

        .nav-item:hover .dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: block;
            padding: 12px 20px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
            border-bottom: 1px solid #eee;
        }

        .dropdown-item:hover {
            background-color: #f1f5f9;
            color: var(--primary-color);
        }

        .dropdown-item i {
            margin-right: 10px;
        }

        .search-container {
            position: relative;
            margin: 0 15px;
        }

        .search-input {
            padding: 10px 15px 10px 40px;
            border: 1px solid #ddd;
            border-radius: 50px;
            font-size: 0.9rem;
            width: 250px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
            width: 300px;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #777;
        }

        .nav-buttons {
            display: flex;
            align-items: center;
        }

        .btn {
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 10px;
            border: none;
        }

        .btn-login {
            background-color: transparent;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .btn-login:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-signup {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-signup:hover {
            background-color: var(--secondary-color);
        }

        .hamburger {
            display: none;
            cursor: pointer;
            font-size: 1.5rem;
            color: var(--text-color);
        }

        .content {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }

        .content h1 {
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .content p {
            margin-bottom: 15px;
        }

        @media screen and (max-width: 968px) {
            .search-input {
                width: 180px;
            }
            
            .search-input:focus {
                width: 200px;
            }
        }

        @media screen and (max-width: 768px) {
            .hamburger {
                display: block;
            }

            .nav-menu {
                position: fixed;
                top: 70px;
                left: -100%;
                background-color: white;
                width: 100%;
                height: calc(100vh - 70px);
                flex-direction: column;
                align-items: flex-start;
                padding: 20px;
                transition: all 0.5s ease;
                box-shadow: var(--shadow);
                overflow-y: auto;
            }

            .nav-menu.active {
                left: 0;
            }

            .nav-item {
                width: 100%;
                margin: 5px 0;
            }

            .nav-link {
                width: 100%;
                padding: 15px;
                justify-content: space-between;
            }

            .dropdown {
                position: static;
                width: 100%;
                opacity: 1;
                visibility: visible;
                transform: translateY(0);
                display: none;
                box-shadow: none;
                margin: 10px 0;
            }

            .nav-item:hover .dropdown {
                display: none;
            }

            .dropdown.active {
                display: block;
            }

            .dropdown-item {
                padding: 15px 15px 15px 30px;
            }

            .search-container {
                width: 100%;
                margin: 15px 0;
            }

            .search-input {
                width: 100%;
            }

            .search-input:focus {
                width: 100%;
            }

            .nav-buttons {
                width: 100%;
                justify-content: center;
                margin-top: 20px;
            }

            .btn {
                width: 45%;
                text-align: center;
            }
            
            /* Arrow rotation for dropdown indicators */
            .nav-link.has-dropdown.active i.fa-chevron-down {
                transform: rotate(180deg);
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <a href="#" class="logo">
                <i class="fas fa-rocket"></i>
                BrandName
            </a>

            <div class="hamburger" id="hamburger">
                <i class="fas fa-bars"></i>
            </div>

            <ul class="nav-menu" id="nav-menu">
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-home"></i>
                        Home
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="#" class="nav-link has-dropdown">
                        <i class="fas fa-info-circle"></i>
                        About
                        <i class="fas fa-chevron-down" style="margin-left: 5px; font-size: 0.8rem;"></i>
                    </a>
                    <div class="dropdown">
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            Our History
                        </a>
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-users"></i>
                            Team
                        </a>
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-award"></i>
                            Achievements
                        </a>
                    </div>
                </li>
                
                <li class="nav-item">
                    <a href="#" class="nav-link has-dropdown">
                        <i class="fas fa-box-open"></i>
                        Products
                        <i class="fas fa-chevron-down" style="margin-left: 5px; font-size: 0.8rem;"></i>
                    </a>
                    <div class="dropdown">
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-mobile-alt"></i>
                            Electronics
                        </a>
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-tshirt"></i>
                            Clothing
                        </a>
                        <a href="#" class="dropdown-item">
                            <i class="fas fa-utensils"></i>
                            Food Items
                        </a>
                    </div>
                </li>
                
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-blog"></i>
                        Blog
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-envelope"></i>
                        Contact
                    </a>
                </li>

                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Search...">
                </div>

                <div class="nav-buttons">
                    <button class="btn btn-login">Login</button>
                    <button class="btn btn-signup">Sign Up</button>
                </div>
            </ul>
        </div>
    </nav>

    <div class="content">
        <h1>Responsive Navigation Menu</h1>
        <p>This page demonstrates a fully responsive navigation menu with a working hamburger menu on mobile devices.</p>
        <p>Try resizing your browser window to see the responsive behavior. On screens smaller than 768px, the hamburger menu will appear.</p>
    </div>

    <script>
        // Toggle mobile menu
        const hamburger = document.getElementById('hamburger');
        const navMenu = document.getElementById('nav-menu');

        hamburger.addEventListener('click', (e) => {
            e.stopPropagation();
            navMenu.classList.toggle('active');
            hamburger.innerHTML = navMenu.classList.contains('active') 
                ? '<i class="fas fa-times"></i>' 
                : '<i class="fas fa-bars"></i>';
        });

        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!hamburger.contains(e.target) && !navMenu.contains(e.target) && navMenu.classList.contains('active')) {
                navMenu.classList.remove('active');
                hamburger.innerHTML = '<i class="fas fa-bars"></i>';
                
                // Close all dropdowns when closing the menu
                document.querySelectorAll('.dropdown').forEach(dropdown => {
                    dropdown.classList.remove('active');
                });
                
                // Reset arrow rotations
                document.querySelectorAll('.nav-link.has-dropdown').forEach(link => {
                    link.classList.remove('active');
                });
            }
        });

        // Handle dropdowns on mobile
        const dropdownLinks = document.querySelectorAll('.nav-link.has-dropdown');
        
        dropdownLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    const dropdown = link.nextElementSibling;
                    const isActive = dropdown.classList.contains('active');
                    
                    // Close all other dropdowns
                    document.querySelectorAll('.dropdown').forEach(d => {
                        if (d !== dropdown) {
                            d.classList.remove('active');
                        }
                    });
                    
                    // Toggle current dropdown
                    dropdown.classList.toggle('active');
                    
                    // Toggle arrow rotation
                    link.classList.toggle('active');
                }
            });
        });
    </script>
</body>
</html>