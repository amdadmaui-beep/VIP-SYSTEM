<?php
$ownerRoleIds = getOwnerRoleIds($conn);
$isOwnerDashboard = in_array((int)($_SESSION['user_role'] ?? 0), $ownerRoleIds, true);
$dashboardRoleCss = $isOwnerDashboard ? 'assets/css/owner.css' : 'assets/css/manager.css';
?>
<!DOCTYPE html>
<html lang="en" class="bg-slate-50 min-h-screen">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - VIP Villanueva Ice Plant</title>
    <meta name="description" content="Villanueva Ice Plant management dashboard — sales analytics, delivery scheduling, and inventory monitoring.">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- FontAwesome styling for Sidebar items -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <!-- Base CSS for Sidebar compatibility -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        violet: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            200: '#ddd6fe',
                            300: '#c4b5fd',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7132f5',
                            700: '#5b1ecf',
                            800: '#5741d8',
                            900: '#4c1d95',
                        }
                    },
                    keyframes: {
                        'slide-up-3d': {
                            '0%': { opacity: '0', transform: 'perspective(1200px) translateY(60px) rotateX(-15deg) scale(0.95)' },
                            '100%': { opacity: '1', transform: 'perspective(1200px) translateY(0) rotateX(0deg) scale(1)' },
                        },
                        'float': {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                        'pulse-glow': {
                            '0%, 100%': { filter: 'drop-shadow(0 0 10px rgba(139, 92, 246, 0.2))' },
                            '50%': { filter: 'drop-shadow(0 0 30px rgba(139, 92, 246, 0.6))' },
                        },
                        'fade-in': {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        }
                    },
                    animation: {
                        'slide-up-3d': 'slide-up-3d 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards',
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-glow': 'pulse-glow 3s ease-in-out infinite',
                        'fade-in': 'fade-in 0.5s ease-out forwards',
                    }
                }
            }
        }
    </script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@1.16.0/dist/umd/lucide.min.js" integrity="sha384-ZgnJ3Zpr70Xoify35DjOZWqHib1iYJBpYpQUIEpDASG9+fJ745WzNQuC004dwU0W" crossorigin="anonymous"></script>
    <!-- ApexCharts -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($dashboardRoleCss, ENT_QUOTES, 'UTF-8'); ?>">
</head>
