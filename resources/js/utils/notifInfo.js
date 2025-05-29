export default function notifInfo(n){
    const t = n.type.split('\\').pop();
    const d = n.data;

    switch (t){
        case 'ArtworkLiked':
            return {
                html:
                    `<a class="link no-underline text-blue-500" href="/profile/${d.liker_id}">${d.liker_name}</a>
                     лайкнул ваш арт
                     <a class="link no-underline text-blue-500 ml-1" href="${d.artwork_url}">«${d.artwork_title}»</a>`,
            };

        case 'CommentReceived':
            return {
                html:
                    `<a class="link no-underline text-blue-500" href="/profile/${d.commenter_id}">${d.commenter_name}</a>
                        прокомментировал ваш
                     <a class="link no-underline text-blue-500 ml-1" href="${d.artwork_url}">«${d.artwork_title}»</a>`,
                extra: d.excerpt
            };

        case 'ComplaintCreated':
            return {
                html:
                    `Жалоба от <a class="link no-underline text-blue-500" href="/profile/${d.user_id}">${d.user_name}</a>
                     на <a class="link no-underline text-blue-500" href="${d.subject_url}">«${d.subject_title}»</a>`
            };

        case 'ContentBlocked':
            return {
                html:
                    `${d.message}
                     <a class="link no-underline text-blue-500 ml-1" href="${d.url}">«${d.title}»</a>`,
                extra: d.note
            };

        case 'ComplaintRejected':
            return {
                html:
                    `Ваша жалоба на
                     <a class="link no-underline text-blue-500" href="${d.url}">«${d.title}»</a> отклонена`,
                extra: d.note
            };

        /*  если добавите ComplaintApproved — обрабатывайте тут же  */

        default:
            return { html: 'Уведомление' }
    }
}
