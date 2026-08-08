import { useBlockProps } from '@wordpress/block-editor';
import { Disabled } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

// ServerSideRender hits render.php so authors preview the real calendar (live
// availability and all) right in the editor. Wrapped in <Disabled> so its links
// and month paging don't fire on the canvas, while the block stays selectable.
//
// The block has no settings of its own — any heading/intro copy is composed
// around it with core/heading + core/paragraph in the CMS.
export default function Edit() {
    const blockProps = useBlockProps();

    return (
        <div {...blockProps}>
            <Disabled>
                <ServerSideRender block="todaytix/calendar" />
            </Disabled>
        </div>
    );
}
