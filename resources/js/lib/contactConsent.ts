export type ConsentFlag =
    | 'whatsapp_opt_in'
    | 'whatsapp_broadcast_opt_in'
    | 'whatsapp_broadcast_opt_out'
    | 'email_broadcast_opt_in'
    | 'email_broadcast_unsubscribed';

export type ConsentSourceField =
    | 'whatsapp_opt_in_source'
    | 'whatsapp_broadcast_opt_in_source'
    | 'whatsapp_broadcast_opt_out_source'
    | 'email_broadcast_opt_in_source'
    | 'email_broadcast_unsubscribe_source';

export type ConsentAtField =
    | 'whatsapp_opt_in_at'
    | 'whatsapp_broadcast_opt_in_at'
    | 'whatsapp_broadcast_opt_out_at'
    | 'email_broadcast_opt_in_at'
    | 'email_broadcast_unsubscribed_at';

export type ConsentDefinition = {
    flag: ConsentFlag;
    source: ConsentSourceField;
    at: ConsentAtField;
    label: string;
    help: string;
};

export const consentGroups: Array<{ title: string; description: string; items: ConsentDefinition[] }> = [
    {
        title: 'WhatsApp communication',
        description:
            'Opt-in allows business WhatsApp messages such as invoices and reminders. WhatsApp broadcasts (promotional messages) need their own separate agreement.',
        items: [
            {
                flag: 'whatsapp_opt_in',
                source: 'whatsapp_opt_in_source',
                at: 'whatsapp_opt_in_at',
                label: 'Customer agreed to receive WhatsApp messages from us',
                help: 'Needed for WhatsApp invoices, quotes and reminders. On its own it does not allow broadcasts.',
            },
            {
                flag: 'whatsapp_broadcast_opt_in',
                source: 'whatsapp_broadcast_opt_in_source',
                at: 'whatsapp_broadcast_opt_in_at',
                label: 'Customer agreed to receive WhatsApp broadcasts',
                help: 'Only record this when the customer explicitly agreed to promotional WhatsApp messages.',
            },
            {
                flag: 'whatsapp_broadcast_opt_out',
                source: 'whatsapp_broadcast_opt_out_source',
                at: 'whatsapp_broadcast_opt_out_at',
                label: 'Customer does not want WhatsApp broadcasts',
                help: 'Blocks WhatsApp broadcasts even if an agreement was recorded earlier. Invoices, quotes, reminders and replies are not affected.',
            },
        ],
    },
    {
        title: 'Email broadcasts',
        description:
            'Controls promotional emails only. Invoice, quote, payment and reminder emails are always sent as normal.',
        items: [
            {
                flag: 'email_broadcast_opt_in',
                source: 'email_broadcast_opt_in_source',
                at: 'email_broadcast_opt_in_at',
                label: 'Customer agreed to receive email broadcasts',
                help: 'Only customers with this recorded can receive email broadcasts.',
            },
            {
                flag: 'email_broadcast_unsubscribed',
                source: 'email_broadcast_unsubscribe_source',
                at: 'email_broadcast_unsubscribed_at',
                label: 'Customer unsubscribed from email broadcasts',
                help: 'Blocks email broadcasts even if an opt-in was recorded earlier. Also set automatically when the customer uses the unsubscribe link.',
            },
        ],
    },
];
