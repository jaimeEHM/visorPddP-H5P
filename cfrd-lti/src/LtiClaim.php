<?php

namespace Cfrd\Lti;

/**
 * URIs de claims LTI 1.3 reutilizables.
 */
final class LtiClaim
{
    public const VERSION = 'https://purl.imsglobal.org/spec/lti/claim/version';

    public const MESSAGE_TYPE = 'https://purl.imsglobal.org/spec/lti/claim/message_type';

    public const DEPLOYMENT_ID = 'https://purl.imsglobal.org/spec/lti/claim/deployment_id';

    public const CONTENT_ITEMS = 'https://purl.imsglobal.org/spec/lti/claim/content_items';

    public const DEEP_LINKING_SETTINGS = 'https://purl.imsglobal.org/spec/lti/claim/deep_linking_settings';

    public const TARGET_LINK_URI = 'https://purl.imsglobal.org/spec/lti/claim/target_link_uri';

    public const NAMES_ROLES_SERVICE = 'https://purl.imsglobal.org/spec/lti/claim/namesroleservice';

    /** @see AGS 2.0 — claim de endpoints (lineitems, scores, etc.) */
    public const ENDPOINT = 'https://purl.imsglobal.org/spec/lti/claim/endpoint';

    public const RESOURCE_LINK = 'LtiResourceLinkRequest';

    public const DEEP_LINKING_REQUEST = 'LtiDeepLinkingRequest';

    public const DEEP_LINKING_RESPONSE = 'LtiDeepLinkingResponse';

    public const LTI_VERSION = '1.3.0';

    public const NRPS_SCOPE_CONTEXT_MEMBERSHIP_READONLY = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';

    public const AGS_SCOPE_LINEITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';

    public const AGS_SCOPE_LINEITEM_READONLY = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly';

    public const AGS_SCOPE_RESULT_READONLY = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';

    public const AGS_SCOPE_SCORE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
}
