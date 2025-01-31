/**
 * @author Adam Charron <adam.c@vanillaforums.com>
 * @copyright 2009-2024 Vanilla Forums Inc.
 * @license gpl-2.0-only
 */

import { IntegrationButtonAndModal } from "@library/features/discussions/integrations/Integrations";
import {
    AttachmentIntegrationsContextProvider,
    IntegrationContextProvider,
    useAttachmentIntegrations,
} from "@library/features/discussions/integrations/Integrations.context";
import { IAttachment } from "@library/features/discussions/integrations/Integrations.types";
import { DiscussionAttachment } from "@vanilla/addon-vanilla/thread/DiscussionAttachmentsAsset";
import React from "react";

interface IProps {
    attachments?: IAttachment[];
}

export function LegacyThreadAttachmentsAsset(props: IProps) {
    const { attachments } = props;

    if (!attachments || attachments.length === 0) {
        // Nothing to render.
        // Because this is mounted at the top level, we can't return an empty fragment.
        return <div></div>;
    }

    return (
        <div>
            <AttachmentIntegrationsContextProvider>
                {props.attachments?.map((attachment, i) => {
                    return <DiscussionAttachment key={i} attachment={attachment} />;
                })}
            </AttachmentIntegrationsContextProvider>
        </div>
    );
}

interface IFormProps {
    recordType: string;
    recordID: number;
    redirectTarget: string;
}

export function LegacyIntegrationsOptionsMenuItems(props: IFormProps) {
    return (
        <AttachmentIntegrationsContextProvider>
            <LegacyIntegrationsOptionsMenuItemsImpl {...props} />
        </AttachmentIntegrationsContextProvider>
    );
}

function LegacyIntegrationsOptionsMenuItemsImpl(props: IFormProps) {
    const integrations = useAttachmentIntegrations();

    return (
        <>
            {integrations
                .filter((integration) => integration.recordTypes.includes(props.recordType))
                .map((integration) => {
                    return (
                        <IntegrationContextProvider
                            key={integration.attachmentType}
                            recordType={props.recordType}
                            attachmentType={integration.attachmentType}
                            recordID={props.recordID}
                        >
                            <IntegrationButtonAndModal
                                onSuccess={async () => {
                                    window.location.href = props.redirectTarget;
                                }}
                            />
                        </IntegrationContextProvider>
                    );
                })}
        </>
    );
}
