# NOTE
If your current `agent/agent.php` lacks the `BACKUP_RESTORE_AS_NEW` case, add:

```php
case 'BACKUP_RESTORE_AS_NEW': return backup_restore_as_new($p, $bridge);
```
and include the `backup_restore_as_new` function (see `patches/agent_backup_restore_as_new.php` in this pack).
