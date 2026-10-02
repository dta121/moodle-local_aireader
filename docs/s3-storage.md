# Amazon S3 audio storage

AI Reader can keep generated MP3 files in a private Amazon S3 bucket. The
default remains Moodle's File API, including any alternative filesystem
already configured for the site. Enabling S3 requires no new narration or
OpenAI calls: a background task uploads existing audio as well as new audio.

## Choose a storage mode

| Mode | Behaviour |
| --- | --- |
| Moodle storage | Store new audio through Moodle's File API. Existing S3 audio stays readable. |
| S3 mirror | Keep audio in Moodle and S3. Existing remote-only audio is copied back to Moodle. |
| S3 primary | Upload audio to S3, verify the upload, then remove the logical Moodle file. |

S3 transfers are separate from narration generation. If credentials, the
network or S3 fail, newly generated audio remains available locally and a
later sync retries the transfer. In primary mode, Moodle's normal file-pool
trash cleanup reclaims disk space after the local file is deleted; space
does not necessarily fall immediately.

## Server prerequisites

1. Keep Moodle cron running. The **Synchronise AI narration audio with
   Amazon S3** task is scheduled every five minutes and processes a bounded
   batch each time, so a large existing library takes multiple runs.
2. Allow outbound HTTPS from both the web server and cron workers to S3 and
   any AWS credential services they use.
3. Keep Moodle's AWS SDK for PHP v3 dependencies installed. Supported
   Moodle versions provide it through core's autoloader, using the bundled
   `lib/aws-sdk` or root `vendor/` directory. AI Reader uses that installation
   automatically. If it is missing, restore the dependencies for your Moodle
   version before enabling S3. Local-only storage works without the SDK.
4. Provide AWS credentials to both PHP web requests and cron. On EC2,
   attach an IAM instance role; on ECS, use the task role. AI Reader uses
   the SDK's default credential provider chain, which also supports
   server-managed environment variables and shared AWS profiles. The plugin
   does not store AWS access keys in Moodle settings. Credentials saved for
   a developer's CLI account are not automatically available to PHP-FPM or
   the cron user. See the [AWS credential provider documentation](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_default_chain.html).

## Bucket and IAM configuration

Use an existing private bucket or create one in the intended AWS region.
Keep all four S3 Block Public Access controls enabled and use Bucket owner
enforced Object Ownership. AI Reader does not require public access or
object ACLs. Configure encryption at the bucket level; the plugin does not
override bucket encryption settings. See [AWS Block Public Access](https://docs.aws.amazon.com/AmazonS3/latest/userguide/configuring-block-public-access-bucket.html).

For a dedicated audio bucket that already exists, an AWS administrator can
apply these settings. Replace `YOUR-PRIVATE-BUCKET` before running them:

```sh
aws s3api put-public-access-block \
  --bucket YOUR-PRIVATE-BUCKET \
  --public-access-block-configuration \
  'BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true'

aws s3api put-bucket-ownership-controls \
  --bucket YOUR-PRIVATE-BUCKET \
  --ownership-controls '{"Rules":[{"ObjectOwnership":"BucketOwnerEnforced"}]}'

aws s3api put-bucket-encryption \
  --bucket YOUR-PRIVATE-BUCKET \
  --server-side-encryption-configuration \
  '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"}}]}'
```

These settings apply to the entire bucket. An existing shared bucket may
already use a customer-managed KMS key; retain that configuration and grant
the Moodle role access to that key instead of replacing it with AES256.

Use a separate prefix for each Moodle environment, such as
`moodle-aireader/staging` and `moodle-aireader/production`. AI Reader also
adds a stable site namespace under the prefix to separate independent
sites. Grant the Moodle server role object permissions scoped to its
prefix. Replace the example bucket and prefix below with the configured values:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ManageAIReaderAudio",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::YOUR-PRIVATE-BUCKET/moodle-aireader/staging/*"
    }
  ]
}
```

The plugin uses recorded object keys rather than listing the bucket, so it
does not need `s3:ListBucket`. If the bucket uses a customer-managed KMS key,
the role also needs the corresponding key permissions for uploads and
downloads, and the key policy must allow that role. Ensure bucket policies
permit the same operations; a deny in a bucket or organization policy can
override an IAM allow. AWS documents [bucket policy examples](https://docs.aws.amazon.com/AmazonS3/latest/userguide/example-bucket-policies.html).

Avoid lifecycle rules that expire current audio or move it to an archive
class requiring a restore before reading. For a versioned bucket, ordinary
deletions create delete markers; noncurrent versions remain subject to the
bucket's retention and lifecycle settings.

## Enable and verify

Open **Site administration → Plugins → Local plugins → AI Reader →
Storage**. Set the bucket name, its AWS region and the object prefix. Use
the plain bucket name, without `s3://` or a path. Prefixes support up to 100
lowercase letters, digits, `/`, `_` and `-`. An empty prefix uses only the automatic
site namespace.

Start with **S3 mirror (keep Moodle copy)**, save the settings and let cron
run. To inspect or run a bounded batch immediately, execute the following
as the Moodle service user from the plugin directory, normally
`/path/to/moodle/local/aireader` or, on versions with a public directory,
`/path/to/moodle/public/local/aireader`:

```sh
php cli/sync_s3.php --status
php cli/sync_s3.php --limit=25 --dry-run
php cli/sync_s3.php --assetid=123 --dry-run
php cli/sync_s3.php --assetid=123
php cli/sync_s3.php --limit=25
```

`--status` reports pending uploads, ready copies, pending deletions,
remote-only assets and recent transfer errors without making S3 requests.
Replace `123` with an existing audio asset ID from the Moodle database.
Dry-run reports planned work without uploading or deleting audio. A real
run follows the current storage mode: primary mode can remove local files
after successful upload verification. Review command output and the
scheduled task log for failures. Play an existing Page or Book narration,
seek within it, download the MP3 and try a course ZIP download with an
account that can access those activities.

Once the mirror works, select **S3 primary (offload Moodle copy)** if the
goal is to reduce persistent Moodle storage. Cron then offloads eligible
local audio after verifying its S3 copy. Failed uploads leave local audio
in place. Keep the SDK and credentials available for both web requests
and background tasks while any remote audio remains.

## Migrate existing audio and free Moodle storage

Open **Site administration → Plugins → Local plugins → AI Reader audio
storage** (`/local/aireader/s3storage.php`). The page shows how much audio
is only in Moodle, in both places or only in S3, how many assets are still
to transfer and the most recent S3 errors.

1. Select **Test S3 connection**. The server writes, reads back and deletes a
   small object under `<prefix>/<site namespace>/connection-test/`. A failure
   names the S3 operation and AWS error code, for example
   `PutObject: AccessDenied (HTTP 403)`.
2. Select **Migrate all audio to S3 and remove Moodle copies** and confirm.
   This switches the storage mode to **S3 primary**, clears earlier retry
   cooldowns and queues a background task. The task works for up to ten
   minutes per run and queues its successor until nothing is left, so a
   large library migrates without a single long cron process.
3. Refresh the page to follow progress. Each Moodle copy is deleted only
   after its upload is verified; uploads that fail stay in Moodle, show in
   **Recent S3 errors** and are retried by the five-minute sync.

From the shell, the equivalent is to select S3 primary and run
`php cli/sync_s3.php --all --limit=100`, which keeps processing batches
until every asset has been tried once.

## Saylor configuration (dev.sylr.org)

Set up on 2026-10-01 for `dev.sylr.org` (EC2 `moodle-test`):

| AI Reader setting | Value |
| --- | --- |
| S3 bucket | `saylor-moodle-aireader-audio-806862010366` |
| S3 region | `us-east-1` |
| S3 object prefix | `dev` |

The bucket blocks all public access, enforces bucket-owner object ownership,
uses SSE-S3 (AES256) with a bucket key, and its bucket policy denies non-TLS
requests. `moodle-test` uses the shared instance role
`AmazonSSMRoleForInstancesQuickSetup`; the inline policy
`local-aireader-s3-audio-dev` grants `s3:GetObject`, `s3:PutObject` and
`s3:DeleteObject` on `saylor-moodle-aireader-audio-806862010366/dev/*` only
when `ec2:SourceInstanceARN` is the `moodle-test` instance, so other
instances sharing the role receive no write access. A production site
should use its own prefix (for example `prod`) and its own policy.

## Access, downloads and storage changes

Learners continue to use Moodle's authenticated `pluginfile.php` endpoint.
Course access, hidden chapter restrictions and the download setting still
apply before audio is served. S3 objects stay private; learners do not need
AWS credentials or public bucket URLs.

For remote-only audio, Moodle fetches the file into a temporary file for
the current request or task. This supports seeking, individual downloads,
course ZIP downloads and Whisper alignment. It also means web and cron
workers need temporary disk space, and playback uses the Moodle server's
bandwidth plus S3 requests. This feature is storage offloading, not direct
delivery from a CDN.

Changing the bucket, region or prefix also migrates existing ready audio
to the new destination while either S3 mode is enabled. Each stored copy
retains its recorded location, so audio stays readable at its old location
until a replacement upload succeeds. The old copy is then queued for
deletion. Keep permission to both locations until migration and cleanup
finish. Do not manually remove old objects while Moodle still references them.

To return to local storage, select **S3 mirror**, let all remote-only files
be restored, then switch to **Moodle storage**. Switching straight to
Moodle storage stops new uploads but does not recall remote-only audio or
delete existing S3 copies. Asset removal and stale-asset cleanup retain
pending remote-deletion records until S3 deletion succeeds. The sync task
processes those records even in Moodle storage mode, so cron and old-location
permissions must stay available while cleanup is pending. The task also
finds assets whose source activity or chapter has disappeared, then retires
their remote copies, so a deletion race does not lose cleanup work.

On versioned buckets, deletion follows the bucket's version-retention policy;
use S3 lifecycle settings to manage noncurrent versions. Before downgrading to
a plugin version without S3 support, restore all local copies with mirror mode
and confirm that `--status` reports zero remote-only assets.

Database and audio backups remain a deployment responsibility. Back up the
plugin's S3 metadata with the Moodle database and protect the matching
bucket objects; a copy of `moodledata` alone cannot restore remote-only
audio. Mirror mode can restore local audio before a full site backup, but
the backup must still include the Moodle database and audio storage.
Course backups contain the plugin's activity settings, not generated
audio files, regardless of the storage mode.
When cloning a Moodle database into another environment, use a separate
S3 prefix and ensure the clone cannot delete objects belonging to the
original site. A copied database retains both its site identifier and
existing remote-object records until those records are handled explicitly.
