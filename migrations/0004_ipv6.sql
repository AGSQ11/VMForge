USE vmforge;

-- Extend ip_pools to allow IPv6; already has 'version' column.
-- Ensure allocations can store IPv6 length
ALTER TABLE ip_allocations MODIFY ip_address VARCHAR(128) NOT NULL;

-- Optional: seed a v6 pool example (commented)
-- INSERT INTO ip_pools(name,cidr,gateway,dns,version) VALUES ('lab-v6','2001:db8:100::/64','2001:db8:100::1','2001:4860:4860::8888,2606:4700:4700::1111',6);
