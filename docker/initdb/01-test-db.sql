-- Dev-only: second schema for the integration test suite.
CREATE DATABASE IF NOT EXISTS familycastel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON familycastel_test.* TO 'fc'@'%';
FLUSH PRIVILEGES;
