<?php

namespace App\Enums;

/**
 * Default roles created at installation. Administrators can create custom
 * roles too (when the license includes "custom_roles").
 */
enum SystemRole: string
{
    case SuperAdmin = 'Super Admin';
    case LabelManager = 'Label Manager';
    case ArtistManager = 'Artist Manager';
    case DistributionManager = 'Distribution Manager';
    case Accountant = 'Accountant';
    case Viewer = 'Viewer';
    case Artist = 'Artist';             // artist accounts: only their own artists' data
}
