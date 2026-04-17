<?php

namespace Cfrd\Lti\DeepLinking;

use Cfrd\Lti\LtiClaim;
use stdClass;

final class DeepLinkingMessageInspector
{
    public function isDeepLinkingRequest(stdClass $idTokenPayload): bool
    {
        $type = $idTokenPayload->{LtiClaim::MESSAGE_TYPE} ?? null;

        return $type === LtiClaim::DEEP_LINKING_REQUEST;
    }

    public function isResourceLinkRequest(stdClass $idTokenPayload): bool
    {
        $type = $idTokenPayload->{LtiClaim::MESSAGE_TYPE} ?? null;

        return $type === LtiClaim::RESOURCE_LINK;
    }
}
