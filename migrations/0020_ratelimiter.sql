-- 0020_ratelimiter.sql
CREATE TABLE IF NOT EXISTS rate_limits (
  k VARCHAR(190) PRIMARY KEY,
  cnt INT NOT NULL,
  window_start TIMESTAMP NOT NULL
);
