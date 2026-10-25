import { FORM48, cellBounds, cellText, form48Cells } from '../lib/form48Layout';

export default function Form48({ dtr }) {
    return <svg xmlns="http://www.w3.org/2000/svg" width="794" height="1123" className="form48-sheet" viewBox={`0 0 ${FORM48.width} ${FORM48.height}`} role="img" aria-label={`Civil Service Form No. 48 for ${dtr.cutoff.label}`}>
        <rect width={FORM48.width} height={FORM48.height} fill="white" />
        {form48Cells(dtr).map((cell, index) => {
            const box = cellBounds(cell);
            return <g key={index} fill="#111" stroke="none">
                {cell.border === 'box' && <rect x={box.x} y={box.y} width={box.width} height={box.height} fill="none" stroke="#111" strokeWidth="0.2" />}
                {cell.border === 'bottom' && <line x1={box.x} y1={box.y + box.height} x2={box.x + box.width} y2={box.y + box.height} stroke="#111" strokeWidth="0.2" />}
                {cellText(cell).map((line, i) => <text key={i} x={line.x} y={line.y} fontFamily="Arial, Helvetica, sans-serif" fontSize={cell.size * 25.4 / 72} fontWeight={cell.bold ? 700 : 400} textAnchor={cell.align === 'left' ? 'start' : cell.align === 'right' ? 'end' : 'middle'}>{line.text}</text>)}
            </g>;
        })}
    </svg>;
}
