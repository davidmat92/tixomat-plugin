/**
 * Tixomat - QR Code Generator
 * Minimal, self-contained QR code renderer for ticket codes.
 * Supports Version 1 (21x21) and Version 2 (25x25), ECC Level L.
 */
(function(){
"use strict";

/* GF(256) */
var EXP=new Array(256),LOG=new Array(256);
(function(){var x=1;for(var i=0;i<255;i++){EXP[i]=x;LOG[x]=i;x*=2;if(x>=256)x^=285;}EXP[255]=EXP[0];})();
function gfMul(a,b){return(a===0||b===0)?0:EXP[(LOG[a]+LOG[b])%255];}

/* Reed-Solomon */
function rsGenPoly(n){
    var g=[1];
    for(var i=0;i<n;i++){
        var ng=new Array(g.length+1);
        for(var k=0;k<ng.length;k++)ng[k]=0;
        for(var j=0;j<g.length;j++){
            ng[j]^=gfMul(g[j],EXP[i]);
            ng[j+1]^=g[j];
        }
        g=ng;
    }
    return g;
}
function rsEncode(data,ecLen){
    var gen=rsGenPoly(ecLen);
    var r=new Array(ecLen);
    for(var i=0;i<ecLen;i++)r[i]=0;
    for(var i=0;i<data.length;i++){
        var f=r[0]^data[i];
        for(var j=0;j<ecLen-1;j++)r[j]=r[j+1]^gfMul(gen[j+1],f);
        r[ecLen-1]=gfMul(gen[ecLen],f);
    }
    return r;
}

/* Alphanumeric Encoding */
var AC="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:";
function encodeData(text){
    text=text.toUpperCase();
    var ver=text.length<=25?1:2;
    var totalBytes=ver===1?19:34;
    var ecBytes=ver===1?7:10;
    var bits=[];
    function pb(v,n){for(var i=n-1;i>=0;i--)bits.push((v>>i)&1);}
    pb(0x2,4);
    pb(text.length,9);
    for(var i=0;i+1<text.length;i+=2)
        pb(AC.indexOf(text[i])*45+AC.indexOf(text[i+1]),11);
    if(text.length%2===1)pb(AC.indexOf(text[text.length-1]),6);
    var cap=totalBytes*8;
    pb(0,Math.min(cap-bits.length,4));
    while(bits.length%8)bits.push(0);
    var pt=0;
    while(bits.length<cap){pb([236,17][pt],8);pt^=1;}
    var data=new Array(totalBytes);
    for(var i=0;i<totalBytes;i++){
        data[i]=0;
        for(var j=0;j<8;j++)data[i]|=bits[i*8+j]<<(7-j);
    }
    var ec=rsEncode(data,ecBytes);
    var all=[];
    function addB(a){for(var i=0;i<a.length;i++)for(var j=7;j>=0;j--)all.push((a[i]>>j)&1);}
    addB(data);addB(ec);
    if(ver===2)for(var i=0;i<7;i++)all.push(0);
    return{bits:all,version:ver};
}

/* Matrix */
function makeMatrix(ver){
    var s=ver*4+17,m=[],res=[];
    for(var i=0;i<s;i++){m[i]=[];res[i]=[];for(var j=0;j<s;j++){m[i][j]=0;res[i][j]=false;}}
    return{mod:m,res:res,size:s};
}
function setM(q,r,c,v){if(r>=0&&r<q.size&&c>=0&&c<q.size){q.mod[r][c]=v?1:0;q.res[r][c]=true;}}

function addFinder(q,row,col){
    for(var dr=-1;dr<=7;dr++)for(var dc=-1;dc<=7;dc++){
        var r=row+dr,c=col+dc;
        if(r<0||r>=q.size||c<0||c>=q.size)continue;
        var b=(dr>=0&&dr<=6&&(dc===0||dc===6))||(dc>=0&&dc<=6&&(dr===0||dr===6))||(dr>=2&&dr<=4&&dc>=2&&dc<=4);
        setM(q,r,c,b);
    }
}
function addAlign(q,r,c){
    for(var dr=-2;dr<=2;dr++)for(var dc=-2;dc<=2;dc++){
        setM(q,r+dr,c+dc,(dr===-2||dr===2||dc===-2||dc===2)||(dr===0&&dc===0));
    }
}
function addFixed(q,ver){
    var s=q.size;
    addFinder(q,0,0);addFinder(q,0,s-7);addFinder(q,s-7,0);
    for(var i=8;i<s-8;i++){setM(q,6,i,i%2===0);setM(q,i,6,i%2===0);}
    if(ver>=2)addAlign(q,s-7,s-7);
    setM(q,4*ver+9,8,true);
    // Reserve format areas
    for(var i=0;i<9;i++){if(!q.res[8][i])setM(q,8,i,false);if(!q.res[i][8])setM(q,i,8,false);}
    for(var i=0;i<8;i++){setM(q,8,s-8+i,false);setM(q,s-8+i,8,false);}
}

/* Data Placement (QR spec zig-zag) */
function placeData(q,bits){
    var s=q.size,bi=0,up=true;
    for(var right=s-1;right>=1;right-=2){
        if(right===6)right=5;
        for(var cnt=0;cnt<s;cnt++){
            var row=up?(s-1-cnt):cnt;
            for(var dx=0;dx<=1;dx++){
                var col=right-dx;
                if(col<0||col>=s||row<0||row>=s)continue;
                if(q.res[row][col])continue;
                q.mod[row][col]=(bi<bits.length)?bits[bi]:0;
                bi++;
            }
        }
        up=!up;
    }
}

/* Mask 0: (r+c)%2===0 */
function applyMask(q){
    for(var r=0;r<q.size;r++)for(var c=0;c<q.size;c++){
        if(!q.res[r][c]&&(r+c)%2===0)q.mod[r][c]^=1;
    }
}

/* Format Info: L + Mask0 = 111011111000100 */
function addFormat(q){
    var s=q.size;
    var f=[1,1,1,0,1,1,1,1,1,0,0,0,1,0,0];
    // Horizontal near top-left
    var hc=[0,1,2,3,4,5,7,8];
    for(var i=0;i<8;i++)q.mod[8][hc[i]]=f[i];
    // Vertical near top-left
    var vr=[8,7,5,4,3,2,1,0];
    for(var i=0;i<8;i++)q.mod[vr[i]][8]=f[i];
    // Horizontal top-right
    for(var i=0;i<8;i++)q.mod[8][s-8+i]=f[7+i];
    // Vertical bottom-left
    for(var i=0;i<7;i++)q.mod[s-1-i][8]=f[i];
    q.mod[s-8][8]=f[7];
}

/* Generate */
function generate(text){
    var enc=encodeData(text);
    var q=makeMatrix(enc.version);
    addFixed(q,enc.version);
    placeData(q,enc.bits);
    applyMask(q);
    addFormat(q);
    return q.mod;
}

/* Render to Canvas */
function render(canvas){
    var text=canvas.getAttribute("data-qr");
    if(!text)return;
    var matrix;
    try{matrix=generate(text);}catch(e){return;}
    var s=matrix.length;
    var w=canvas.width,h=canvas.height;
    var cell=Math.floor(Math.min(w,h)/(s+8));
    if(cell<2)cell=2;
    var total=s*cell;
    var ox=Math.floor((w-total)/2),oy=Math.floor((h-total)/2);
    var ctx=canvas.getContext("2d");
    ctx.fillStyle="#fff";ctx.fillRect(0,0,w,h);
    ctx.fillStyle="#000";
    for(var r=0;r<s;r++)for(var c=0;c<s;c++){
        if(matrix[r][c]===1)ctx.fillRect(ox+c*cell,oy+r*cell,cell,cell);
    }
}

/* Init */
function init(){
    var els=document.querySelectorAll("canvas.tix-mt-qr-canvas[data-qr]");
    for(var i=0;i<els.length;i++)render(els[i]);
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);
else setTimeout(init,0);

window.ehQR={generate:generate,render:render};
})();
