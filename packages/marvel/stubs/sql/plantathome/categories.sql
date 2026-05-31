TRUNCATE TABLE `categories`;

INSERT INTO `categories` (`id`, `name`, `slug`, `language`, `icon`, `image`, `details`, `parent`, `type_id`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1,  'Indoor Plants',      'indoor-plants',    'en', 'Leaf',   NULL, 'Perfect for home and office spaces',     NULL, 1, '2024-01-01 00:00:00', '2024-01-01 00:00:00', NULL),
(2,  'Outdoor Plants',     'outdoor-plants',   'en', 'Tree',   NULL, 'Hardy plants for gardens and balconies', NULL, 1, '2024-01-01 00:00:00', '2024-01-01 00:00:00', NULL),
(3,  'Flowering Plants',   'flowering-plants', 'en', 'Flower', NULL, 'Beautiful blooms for every season',      NULL, 1, '2024-01-01 00:00:00', '2024-01-01 00:00:00', NULL),
(4,  'Succulents & Cacti', 'succulents-cacti', 'en', 'Cactus', NULL, 'Low maintenance, high visual impact',    NULL, 1, '2024-01-01 00:00:00', '2024-01-01 00:00:00', NULL),
(5,  'Air Purifying',      'air-purifying',    'en', 'Wind',   NULL, 'Plants that clean and freshen your air', NULL, 1, '2024-01-01 00:00:00', '2024-01-01 00:00:00', NULL),
(6,  'Gifts & Planters',   'gifts-planters',   'en', 'Gift',   NULL, 'Curated gift sets and premium planters', NULL, 1, '2024-01-01 00:00:00', '2024-01-01 00:00:00', NULL);
