<?php

declare(strict_types=1);

namespace Orchestra\Email\Attachment;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores attachments in a private directory under generated names, like the sheet music of
 * orchestra-library: they must never be reachable through a URL.
 *
 * The directory is `ORCHESTRA_EMAIL_STORAGE_DIR` if defined (preferably outside the web root),
 * otherwise a directory with an unguessable name inside the uploads directory, protected by
 * `.htaccess` (Apache only; nginx must deny it in its configuration).
 */
final class AttachmentStorage
{
    public const OPTION_DIRECTORY_SUFFIX = 'orchestra_email_storage_suffix';

    private const STORAGE_NAME_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private ?string $directory = null;

    /**
     * @param Closure(): string $locate Returns the directory; called when it is first needed
     * @param Closure(string $source, string $target): bool $mover Moves a file into the storage;
     *        defaults to PHP's move_uploaded_file()
     */
    public function __construct(private readonly Closure $locate, private readonly ?Closure $mover = null)
    {
    }

    /**
     * The storage of the site. The directory is determined when it is first needed, because the
     * plugin is created before WordPress knows its uploads directory.
     */
    public static function fromWordPress(): self
    {
        return new self(static function (): string {
            if (\defined('ORCHESTRA_EMAIL_STORAGE_DIR') && \is_string(ORCHESTRA_EMAIL_STORAGE_DIR)) {
                return \untrailingslashit(ORCHESTRA_EMAIL_STORAGE_DIR);
            }

            $suffix = \get_option(self::OPTION_DIRECTORY_SUFFIX);
            if (!\is_string($suffix) || \preg_match('/^[0-9a-z]{32}$/', $suffix) !== 1) {
                $suffix = \strtolower(\wp_generate_password(32, false));
                \update_option(self::OPTION_DIRECTORY_SUFFIX, $suffix, false);
            }

            return \wp_upload_dir(null, false)['basedir'] . '/orchestra-email-' . $suffix;
        });
    }

    public function directory(): string
    {
        return $this->directory ??= ($this->locate)();
    }

    /**
     * Moves an uploaded file into the storage.
     *
     * @return string The generated storage name
     */
    public function store(string $temporaryPath): string
    {
        $this->ensureDirectory();

        $storageName = \wp_generate_uuid4();
        $mover = $this->mover ?? \move_uploaded_file(...);
        if (!$mover($temporaryPath, $this->path($storageName))) {
            throw new RuntimeException('Could not store the attachment.');
        }

        return $storageName;
    }

    public function path(string $storageName): string
    {
        if (\preg_match(self::STORAGE_NAME_PATTERN, $storageName) !== 1) {
            throw new InvalidArgumentException('Invalid storage name.');
        }

        return $this->directory() . '/' . $storageName;
    }

    public function delete(string $storageName): void
    {
        $path = $this->path($storageName);
        if (\is_file($path)) {
            \unlink($path);
        }
    }

    /**
     * Deletes the directory with everything in it. Only for uninstalling.
     */
    public function removeDirectory(): void
    {
        if (!\is_dir($this->directory())) {
            return;
        }
        foreach ((array) \scandir($this->directory()) as $name) {
            if (\is_string($name) && \is_file($this->directory() . '/' . $name)) {
                \unlink($this->directory() . '/' . $name);
            }
        }
        \rmdir($this->directory());
    }

    private function ensureDirectory(): void
    {
        if (!\is_dir($this->directory()) && !\wp_mkdir_p($this->directory())) {
            throw new RuntimeException('Could not create the attachment storage directory.');
        }

        $protection = [
            '.htaccess' => "# Email attachments are only sent by orchestra-email, never downloaded.\n"
                . "Require all denied\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
        ];
        foreach ($protection as $fileName => $content) {
            $path = $this->directory() . '/' . $fileName;
            if (!\is_file($path) && \file_put_contents($path, $content) === false) {
                throw new RuntimeException(\sprintf('Could not write %s.', $path));
            }
        }
    }
}
