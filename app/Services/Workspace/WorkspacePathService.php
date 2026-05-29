<?php

namespace App\Services\Workspace;

use App\Models\Workspace;
use InvalidArgumentException;
use RuntimeException;

class WorkspacePathService
{
    public function resolve(Workspace $workspace): string
    {
        if ($workspace->path === '') {
            throw new RuntimeException('Workspace path is empty.');
        }

        $realPath = realpath($workspace->path);

        if ($realPath === false || ! is_dir($realPath)) {
            throw new RuntimeException("Workspace path does not exist: {$workspace->path}");
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR);
    }

    public function safePath(Workspace $workspace, string $relativePath): string
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        $workspacePath = $this->resolve($workspace);

        $path = $normalized === ''
            ? $workspacePath
            : $workspacePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        $this->ensureInsideWorkspace($workspace, $path);

        return $path;
    }

    public function ensureInsideWorkspace(Workspace $workspace, string $path): void
    {
        $workspacePath = $this->resolve($workspace);
        $candidatePath = file_exists($path) ? realpath($path) : $this->existingAncestor($path);

        if ($candidatePath === false) {
            throw new RuntimeException("Unable to resolve path: {$path}");
        }

        $workspaceComparable = $this->comparablePath($workspacePath);
        $candidateComparable = $this->comparablePath($candidatePath);

        if ($candidateComparable !== $workspaceComparable && ! str_starts_with($candidateComparable, $workspaceComparable.'/')) {
            throw new InvalidArgumentException('Path escapes the configured workspace.');
        }
    }

    public function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            return '';
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path contains a null byte.');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new InvalidArgumentException('Agent paths must be relative to the workspace.');
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== '' && $segment !== '.'));

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Path traversal is not allowed.');
            }
        }

        return implode('/', $segments);
    }

    private function existingAncestor(string $path): string|false
    {
        $current = $path;

        while (! file_exists($current)) {
            $parent = dirname($current);

            if ($parent === $current) {
                return false;
            }

            $current = $parent;
        }

        return realpath($current);
    }

    private function comparablePath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
