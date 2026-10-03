export type BroadcastFormProps = {
    broadcast: {
        id: number;
        name: string;
        channel: string;
        audience_type: string;
        selected_contact_ids: number[];
        subject: string | null;
        body: string | null;
        whatsapp_message: string | null;
    } | null;
    channelOptions: Array<{ value: string; label: string }>;
    audienceOptions: Array<{ value: string; label: string }>;
    contactOptions: Array<{
        id: number;
        name: string;
        status_label: string;
        email: string | null;
        phone: string | null;
    }>;
    whatsappTemplate: {
        configured: boolean;
        name: string | null;
        language: string | null;
        problem: string | null;
        uses_message: boolean;
        message_max_length: number;
    };
    recipientLimit: number;
    broadcastsEnabled: boolean;
};
