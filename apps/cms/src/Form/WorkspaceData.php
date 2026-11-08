<?php

namespace App\Form;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class WorkspaceData
{
    public string $name = '';

    public string $slug = '';

    public string $location = 'local';

    public int $sortOrder = 0;

    public ?UploadedFile $avatarUpload = null;
}
