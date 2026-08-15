import { Link } from '@inertiajs/react'
import type { Paginated } from '@/Types'

interface Props {
    meta: Pick<Paginated<unknown>, 'links' | 'total' | 'current_page' | 'last_page'>
}

export default function Pagination({ meta }: Props) {
    if (meta.last_page <= 1) {
        return <div className="text-body-secondary small">{meta.total} record(s)</div>
    }

    return (
        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div className="text-body-secondary small">
                Page {meta.current_page} of {meta.last_page} · {meta.total} record(s)
            </div>
            <nav>
                <ul className="pagination pagination-sm mb-0">
                    {meta.links.map((link, index) => (
                        <li
                            key={`${link.label}-${index}`}
                            className={`page-item ${link.active ? 'active' : ''} ${link.url === null ? 'disabled' : ''}`}
                        >
                            {link.url === null ? (
                                <span className="page-link" dangerouslySetInnerHTML={{ __html: link.label }} />
                            ) : (
                                <Link
                                    className="page-link"
                                    href={link.url}
                                    preserveScroll
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            </nav>
        </div>
    )
}
