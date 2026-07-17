# IAM Policies

Two ready-to-use policies matching the two retention modes. Replace `YOUR-BACKUP-BUCKET` with your bucket name. If you use a `keyPrefix`, you can further scope the object resource to `arn:aws:s3:::YOUR-BACKUP-BUCKET/yourprefix/*`.

## `retentionMode: 'plugin'` — plugin manages deletion

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "OffsitePluginManaged",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject", "s3:ListBucket", "s3:AbortMultipartUpload"],
      "Resource": ["arn:aws:s3:::YOUR-BACKUP-BUCKET", "arn:aws:s3:::YOUR-BACKUP-BUCKET/*"]
    }
  ]
}
```

## `retentionMode: 'lifecycle'` — no delete permission

Deletion is delegated entirely to bucket lifecycle rules; the plugin credential cannot delete anything. Recommended when you want backups to survive a compromised web server.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "OffsiteLifecycleManagedNoDelete",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:ListBucket", "s3:AbortMultipartUpload"],
      "Resource": ["arn:aws:s3:::YOUR-BACKUP-BUCKET", "arn:aws:s3:::YOUR-BACKUP-BUCKET/*"]
    }
  ]
}
```

## Notes for non-AWS providers

- **Cloudflare R2**: create an API token scoped to the bucket with "Object Read & Write" (plugin mode) or configure R2 lifecycle rules for lifecycle mode.
- **Backblaze B2**: create an application key restricted to the bucket. B2 lifecycle settings live on the bucket itself.
- SSE-KMS (AWS only): add `kms:GenerateDataKey` and `kms:Decrypt` on the KMS key to whichever policy you use.
